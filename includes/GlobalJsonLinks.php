<?php

namespace JsonConfig;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

class GlobalJsonLinks {

	/** Extension data key for global JSON links */
	public const KEY_JSONLINKS = 'JsonConfig:globaljsonlinks';

	private string $wiki;
	private IConnectionProvider $connectionProvider;
	private IDatabase $db;
	private Config $config;

	/**
	 * @var NamespaceInfo
	 */
	private $namespaceInfo;

	/**
	 * @var TitleFormatter
	 */
	private $titleFormatter;

	/**
	 * @var string[]
	 */
	private $canonicalNamespaces;

	/**
	 * Construct a GlobalJsonLinks instance for a certain wiki.
	 *
	 * @param Config $config
	 * @param IConnectionProvider $connectionProvider
	 * @param NamespaceInfo $namespaceInfo
	 * @param TitleFormatter $titleFormatter
	 * @param string $wiki wiki id of the wiki
	 */
	public function __construct( Config $config,
		IConnectionProvider $connectionProvider,
		NamespaceInfo $namespaceInfo,
		TitleFormatter $titleFormatter,
		string $wiki
	) {
		$this->config = $config;
		$this->connectionProvider = $connectionProvider;
		$this->namespaceInfo = $namespaceInfo;
		$this->canonicalNamespaces = $namespaceInfo->getCanonicalNamespaces();
		$this->titleFormatter = $titleFormatter;
		$this->wiki = $wiki;
	}

	/**
	 * Switch to another active wiki
	 *
	 * @param string $wiki wiki id of another wiki to work with
	 * @return self
	 */
	public function forWiki( string $wiki ): self {
		return new self( $this->config,
			$this->connectionProvider,
			$this->namespaceInfo,
			$this->titleFormatter,
			$wiki
		);
	}

	/**
	 * Should we be touching the DB? Leave feature flag option off to
	 * keep it undeployed.
	 */
	public function isActive(): bool {
		return boolval( $this->config->get( 'TrackGlobalJsonLinks' ) );
	}

	/**
	 * Should we use the gjlw_namespace_text field? Keep feature flag off if
	 * needed when coordinating deployment of patch-gjlw_namespace_text.sql
	 * @return bool
	 */
	private function useNamespaceText() {
		return boolval( $this->config->get( 'TrackGlobalJsonLinksNamespaces' ) );
	}

	/**
	 * Are we on the shared repository / store wiki?
	 */
	public function onSharedRepo(): bool {
		return JCHooks::jsonConfigIsStorage( $this->config );
	}

	/**
	 * Lazy-initialize database connection to virtual domain 'virtual-globaljsonlinks'.
	 * This will use the local database if no alternate is configured.
	 */
	private function getDB(): IDatabase {
		$this->db ??= $this->connectionProvider->getPrimaryDatabase( 'virtual-globaljsonlinks' );
		return $this->db;
	}

	/**
	 * Look up or insert a globaljsonlinks_namespace row with a partial
	 * source reference with wiki id and namespace. We will only ever be
	 * operating on one at a time (per source page), on the current wiki.
	 *
	 * @param TitleValue $title
	 * @return int row id of current wiki
	 */
	private function mapWiki( TitleValue $title ): int {
		$db = $this->getDB();

		$fields = [
			'gjlw_wiki' => $this->wiki,
		];
		if ( $this->useNamespaceText() ) {
			// Namespace texts may vary by title in some cases such as localized NS_USER
			// namespaces that vary based on the user's configured gender setting.
			// However there are few variants which repeat a lot, so we break them out
			// to the smaller grouping table along with the source wiki that owns them.
			$fields['gjlw_namespace'] = $title->getNamespace();
			$fields['gjlw_namespace_text'] = $this->titleFormatter->getNamespaceName(
				$title->getNamespace(),
				$title->getDBKey()
			);
		}

		for ( $i = 0; $i < 2; $i++ ) {
			$id = intval( $db->newSelectQueryBuilder()
				->select( [ 'gjlw_id' ] )
				->from( 'globaljsonlinks_wiki' )
				->where( $fields )
				->caller( __METHOD__ )
				->fetchField() );
			if ( $id ) {
				return $id;
			}
			$db->newInsertQueryBuilder()
				->insertInto( 'globaljsonlinks_wiki' )
				->row( $fields )
				->caller( __METHOD__ )
				->ignore()
				->execute();
			if ( $db->affectedRows() > 0 ) {
				return intval( $db->insertId() );
			}
		}
		throw new \RuntimeException( 'Unexpected insert conflict' );
	}

	/**
	 * Retrieve a `globaljsonlinks_wiki` entry for inspection.
	 * @param int $id
	 * @return array|null
	 */
	public function getWiki( $id ): ?array {
		$db = $this->getDB();

		$fields = [ 'gjlw_wiki' ];
		if ( $this->useNamespaceText() ) {
			$fields[] = 'gjlw_namespace';
			$fields[] = 'gjlw_namespace_text';
		}

		$row = $db->newSelectQueryBuilder()
			->select( $fields )
			->from( 'globaljsonlinks_wiki' )
			->where( [ 'gjlw_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return [
			'wiki' => $row->gjlw_wiki,
			'namespace' => isset( $row->gjlw_namespace ) ? intval( $row->gjlw_namespace ) : null,
			'namespaceText' => $row->gjlw_namespace_text ?? null,
		];
	}

	/**
	 * Look up and/or insert a target record in globaljsonlinks_target
	 * We may operate on batches (pages with many charts or maps or other
	 * data usages on them)
	 *
	 * We expect in most cases a small number of new mappings will occur
	 * in any given batch, and that it's acceptable to issue multiple queries
	 * in this case during background batch updates.
	 *
	 * @param TitleValue[] $targets as, possibly remote, title values
	 * @return array<string,int> map of namespace id:title to primary key
	 */
	private function mapTargets( array $targets ): array {
		$db = $this->getDB();

		$results = $db->newSelectQueryBuilder()
			->select( [
				'gjlt_id',
				'gjlt_namespace',
				'gjlt_title',
			] )
			->from( 'globaljsonlinks_target' )
			->where( [
				'gjlt_namespace' => NS_DATA,
				'gjlt_title' => $targets
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$map = [];
		foreach ( $results as $row ) {
			$map[$row->gjlt_title] = intval( $row->gjlt_id );
		}

		$present = array_keys( $map );
		$missing = array_diff( $targets, $present );

		foreach ( $missing as $title ) {
			$fields = [
				'gjlt_namespace' => $title->getNamespace(),
				'gjlt_title' => $title->getDbKey(),
			];
			$id = 0;
			for ( $i = 0; $i < 2; $i++ ) {
				$db->newInsertQueryBuilder()
					->insertInto( 'globaljsonlinks_target' )
					->row( $fields )
					->ignore()
					->caller( __METHOD__ )
					->execute();
				if ( $db->affectedRows() > 0 ) {
					$id = $db->insertId();
				} else {
					if ( !$id ) {
						$id = intval( $db->newSelectQueryBuilder()
							->select( [ 'gjlt_id' ] )
							->from( 'globaljsonlinks_target' )
							->where( $fields )
							->caller( __METHOD__ )
							->fetchField() );
					}
					if ( $id ) {
						$map[$title->getNamespace() . ':' . $title->getDbKey()] = intval( $id );
						break;
					}
				}
			}
			if ( $i === 2 ) {
				throw new \RuntimeException( 'Unexpected insert conflict' );
			}
		}

		return $map;
	}

	/**
	 * Sets the images used by a certain page
	 *
	 * @param TitleValue $title Title of the page
	 * @param TitleValue[] $links Array of titles of referenced resources
	 * @param mixed $ticket Token returned by {@see IConnectionProvider::getEmptyTransactionTicket()}
	 */
	private function insertLinks(
		TitleValue $title, array $links, $ticket
	): void {
		$db = $this->getDB();

		$wikiId = $this->mapWiki( $title );
		$targetMap = $this->mapTargets( $links );

		$insert = [];
		foreach ( $targetMap as $target => $targetId ) {
			$row = [
				'gjl_wiki' => $wikiId,
				'gjl_namespace' => $title->getNamespace(),
				'gjl_title' => $title->getDBkey(),
				'gjl_target' => $targetId,
			];
			$insert[] = $row;
		}

		$ticket = $ticket ?: $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		$insertBatches = array_chunk( $insert, $this->config->get( MainConfigNames::UpdateRowsPerQuery ) );
		foreach ( $insertBatches as $insertBatch ) {
			$db->newInsertQueryBuilder()
				->insertInto( 'globaljsonlinks' )
				->ignore()
				->rows( $insertBatch )
				->caller( __METHOD__ )
				->execute();
			if ( count( $insertBatches ) > 1 ) {
				$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $ticket );
			}
		}
	}

	/**
	 * Deletes all entries from a certain page to certain data pages
	 *
	 * @param TitleValue $title Title of the linking page
	 * @param TitleValue[] $to target pages to delete or null to remove all
	 * @param mixed $ticket Token returned by {@see IConnectionProvider::getEmptyTransactionTicket()}
	 */
	private function deleteLinksFromPage( TitleValue $title, array $to, $ticket ): void {
		$db = $this->getDB();

		$where = [
			'gjl_wiki' => $this->mapWiki( $title ),
			'gjl_namespace' => $title->getNamespace(),
			'gjl_title' => $title->getDBkey(),
		];

		$ticket = $ticket ?: $this->connectionProvider->getEmptyTransactionTicket( __METHOD__ );
		if ( $to ) {
			foreach ( array_chunk( $to, $this->config->get( MainConfigNames::UpdateRowsPerQuery ) ) as $toBatch ) {
				if ( $toBatch ) {
					$targets = $this->mapTargets( $toBatch );
					$where['gjl_target'] = array_values( $targets );
				}
				$db->newDeleteQueryBuilder()
					->deleteFrom( 'globaljsonlinks' )
					->where( $where )
					->caller( __METHOD__ )
					->execute();
				$this->connectionProvider->commitAndWaitForReplication( __METHOD__, $ticket );
			}
		} else {
			$db->newDeleteQueryBuilder()
				->deleteFrom( 'globaljsonlinks' )
				->where( $where )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * Gets list of backlinks for the given target data page.
	 * @param TitleValue $target page name of target data page
	 * @return array[] set of ['wiki', 'namespace', 'namespaceText', 'title'] arrays
	 */
	public function getLinksToTarget( TitleValue $target ): array {
		if ( !$this->isActive() ) {
			return [];
		}

		$db = $this->getDB();
		$cols = [
			'gjlw_wiki',
			'gjl_namespace',
			'gjl_title',
		];
		if ( $this->useNamespaceText() ) {
			$cols[] = 'gjlw_namespace_text';
		}
		// Note this ordering may require filesort of the output set, but this
		// is expected to be small enough for that to be reasonable
		// on any single data page reference.
		//
		// If this proves to be a problem in the future on the DB side, consider
		// loosening the traditional namespace ordering or post-processing.
		// -- bvibber 2025-02-21
		$orderBy = [ 'gjlw_wiki', 'gjl_namespace', 'gjl_title' ];
		$result = $db->newSelectQueryBuilder()
			->select( $cols )
			->from( 'globaljsonlinks' )
			->join( 'globaljsonlinks_target', /* alias: */ null, 'gjl_target=gjlt_id' )
			->join( 'globaljsonlinks_wiki', /* alias: */ null, 'gjl_wiki=gjlw_id' )
			->where( [
				'gjlt_namespace' => $target->getNamespace(),
				'gjlt_title' => $target->getDBkey(),
			] )
			->orderBy( $orderBy )
			->caller( __METHOD__ )
			->fetchResultSet();

		$links = [];
		foreach ( $result as $row ) {
			$links[] = [
				'wiki' => $row->gjlw_wiki,
				'namespace' => intval( $row->gjl_namespace ),
				'namespaceText' => $row->gjlw_namespace_text ??
					$this->canonicalNamespaces[$row->gjl_namespace] ??
					'',
				'title' => $row->gjl_title,
			];
		}
		return $links;
	}

	/**
	 * Gets list of out-links for the given source page.
	 * @param TitleValue $title linking page
	 * @return TitleValue[] set of titles, may be remote
	 */
	public function getLinksFromPage( TitleValue $title ): array {
		if ( !$this->isActive() ) {
			return [];
		}

		$db = $this->getDB();
		$where = [
			'gjlw_wiki' => $this->wiki,
			'gjl_namespace' => $title->getNamespace(),
			'gjl_title' => $title->getDBkey(),
		];
		$result = $db->newSelectQueryBuilder()
			->select( [
				'gjlt_namespace',
				'gjlt_title',
			] )
			->from( 'globaljsonlinks' )
			->join( 'globaljsonlinks_target', /* alias: */ null, 'gjl_target=gjlt_id' )
			->join( 'globaljsonlinks_wiki', /* alias: */ null, 'gjl_wiki=gjlw_id' )
			->where( $where )
			->caller( __METHOD__ )
			->fetchResultSet();

		$links = [];
		foreach ( $result as $row ) {
			$links[] = new TitleValue( intval( $row->gjlt_namespace ), $row->gjlt_title );
		}
		return $links;
	}

	/**
	 * Delete `globaljsonlinks` rows that refer to legacy rows in
	 * `globaljsonlinks_wiki` without the namespace text.
	 *
	 * @param TitleValue $title linking page
	 */
	public function deleteLegacyRows( TitleValue $title ) {
		if ( !$this->isActive() ) {
			return;
		}
		if ( !$this->useNamespaceText() ) {
			return;
		}

		$db = $this->getDB();

		$legacyMapping = intval( $db->newSelectQueryBuilder()
			->select( 'gjlw_id' )
			->from( 'globaljsonlinks_wiki' )
			->where( [
				'gjlw_wiki' => $this->wiki,
				'gjlw_namespace' => null,
				'gjlw_namespace_text' => null,
			] )
			->join( 'globaljsonlinks', /* alias: */ null, [
				'gjl_wiki=gjlw_id',
				'gjl_namespace' => $title->getNamespace(),
				'gjl_title' => $title->getDBkey(),
			] )
			->caller( __METHOD__ )
			->fetchField() );
		if ( $legacyMapping === 0 ) {
			return;
		}

		$db->newDeleteQueryBuilder()
			->deleteFrom( 'globaljsonlinks' )
			->where( [
				'gjl_wiki' => $legacyMapping,
				'gjl_namespace' => $title->getNamespace(),
				'gjl_title' => $title->getDBkey(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Extract the saved JsonConfig usage links from the given parser
	 * output object and update the database to match.
	 *
	 * @param TitleValue $title
	 * @param TitleValue[] $pages
	 * @param mixed $ticket Token returned by {@see IConnectionProvider::getEmptyTransactionTicket()}
	 */
	public function updateLinks( TitleValue $title, array $pages, $ticket ) {
		if ( !$this->isActive() ) {
			return;
		}

		$this->deleteLegacyRows( $title );

		$existing = $this->getLinksFromPage( $title );

		// Calculate changes
		$added = array_diff( $pages, $existing );
		$removed = array_values( array_diff( $existing, $pages ) );

		// Add new usages and delete removed
		if ( $added ) {
			$this->insertLinks( $title, $added, $ticket );
		}
		if ( $removed ) {
			$this->deleteLinksFromPage( $title, $removed, $ticket );
		}
	}

	public function batchQuery( TitleValue $target ): GlobalJsonLinksQuery {
		return new GlobalJsonLinksQuery( $this->connectionProvider,
			$this->namespaceInfo, $target );
	}

	/**
	 * Returns a count of how many global JSON link records match Data: pages
	 * with the given suffix on the current wiki, or globally.
	 *
	 * This can be used to track the number of unique usages of a Data: page
	 * type like ".tab" or ".chart" across the entire wiki, or all wikis.
	 *
	 * Warning: this will become more expensive over time as it's not well
	 * optimized for suffix lookups on gjlt_title. Consider adding a suffix
	 * column with special index in future.
	 *
	 * @param string $suffix the suffix to check for
	 * @param bool $global whether to check on all connected wikis
	 */
	public function countLinksMatchingSuffix( string $suffix, bool $global = false ): int {
		$db = $this->getDB();
		$matcher = $db->expr(
			'gjlt_title',
			IExpression::LIKE,
			new LikeValue(
				$db->anyString(),
				$suffix
			)
			);
		if ( $global ) {
			$builder = $db->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'globaljsonlinks' )
				->where( '1' )
				->join( 'globaljsonlinks_target', /* alias: */ null, [
					'gjl_target=gjlt_id',
					'gjlt_namespace' => NS_DATA,
					$matcher
				] );
		} else {
			// Note the indexe hints force the database to first
			// chop down the input set by title suffix, which is currently
			// fast as of July 2025 because there are many more .tab and .map
			// than .chart pages in use.
			//
			// This was particularly slowing down per-site queries on Commons
			// in production, because a *lot* of entries were getting searched
			// from the other side of the join that were irrelevant.
			$builder = $db->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'globaljsonlinks' )
				->join( 'globaljsonlinks_target', /* alias: */ null, [
					'gjl_target=gjlt_id',
					$matcher
				] )
				->useIndex( 'gjlt_namespace_title' )
				->join( 'globaljsonlinks_wiki', /* alias: */ null, [
					'gjl_wiki=gjlw_id',
				] )
				->ignoreIndex( 'gjlw_wiki_id_namespace' )
				->where( [
					'gjlt_namespace' => NS_DATA,
					'gjlw_wiki' => $this->wiki,
				] );
		}
		$builder = $builder->caller( __METHOD__ );
		return intval( $builder->fetchField() );
	}
}
