<?php

namespace JsonConfig;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\TitleValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

class GlobalJsonLinks {

	/** Extension data key for global JSON links */
	public const KEY_JSONLINKS = 'JsonConfig:globaljsonlinks';

	/** Config vars needed for operation */
	public const CONFIG_OPTIONS = [
		'TrackGlobalJsonLinks',
		MainConfigNames::UpdateRowsPerQuery
	];

	/** @var string */
	private $wiki;

	/**
	 * @var IConnectionProvider
	 */
	private $connectionProvider;

	/**
	 * @var IDatabase
	 */
	private $db;

	/**
	 * @var ServiceOptions
	 */
	private $config;

	/**
	 * Construct a GlobalJsonLinks instance for a certain wiki.
	 *
	 * @param ServiceOptions $config
	 * @param IConnectionProvider $connectionProvider
	 * @param string $wiki wiki id of the wiki
	 */
	public function __construct( ServiceOptions $config, IConnectionProvider $connectionProvider, $wiki ) {
		$this->config = $config;
		$this->connectionProvider = $connectionProvider;
		$this->wiki = $wiki;
	}

	/**
	 * Switch to another active wiki
	 *
	 * @param string $wiki wiki id of another wiki to work with
	 * @return GlobalJsonLinks
	 */
	public function forWiki( $wiki ) {
		return new GlobalJsonLinks( $this->config, $this->connectionProvider, $wiki );
	}

	/**
	 * Should we be touching the DB? Leave feature flag option off to
	 * keep it undeployed.
	 * @return bool
	 */
	private function isActive() {
		return boolval( $this->config->get( 'TrackGlobalJsonLinks' ) );
	}

	/**
	 * Lazy-initialize database connection to virtual domain 'virtual-globaljsonlinks'.
	 * This will use the local database if no alternate is configured.
	 * @return IDatabase
	 */
	private function getDB() {
		if ( !$this->db ) {
			$this->db = $this->connectionProvider->getPrimaryDatabase( 'virtual-globaljsonlinks' );
		}
		return $this->db;
	}

	/**
	 * Look up or insert a globaljsonlinks_namespace row with a partial
	 * source reference with wiki id. We will only ever be operating on
	 * one at a time (per source page), the current wiki.
	 *
	 * @return int row id of current wiki
	 */
	private function mapWiki() {
		$db = $this->getDB();

		$fields = [
			'gjlw_wiki' => $this->wiki,
		];

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
	 * Look up and/or insert a target record in globaljsonlinks_target
	 * We may operate on batches (pages with many charts or maps or other
	 * data usages on them)
	 *
	 * We expect in most cases a small number of new mappings will occur
	 * in any given batch, and that it's acceptable to issue multiple queries
	 * in this case during background batch updates.
	 *
	 * @param string[] $targets as normalized dbkeys
	 * @return array map of title to primary key
	 */
	private function mapTargets( array $targets ) {
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
				'gjlt_namespace' => NS_DATA,
				'gjlt_title' => $title,
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
							->fetchField() );
					}
					if ( $id ) {
						$map[$title] = intval( $id );
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
	 * @param string[] $links Array of db keys of images used
	 * @param int $pageIdFlags
	 * @param int|null $ticket
	 */
	private function insertLinks(
		TitleValue $title, array $links, $pageIdFlags = IDBAccessObject::READ_LATEST, $ticket = null
	) {
		$db = $this->getDB();

		$wikiId = $this->mapWiki();
		$targetMap = $this->mapTargets( $links );

		$insert = [];
		foreach ( $targetMap as $target => $targetId ) {
			$insert[] = [
				'gjl_wiki' => $wikiId,
				'gjl_namespace' => $title->getNamespace(),
				'gjl_title' => $title->getDBkey(),
				'gjl_target' => $targetId,
			];
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
	 * @param string[]|null $to target pages to delete or null to remove all
	 * @param int|null $ticket
	 */
	private function deleteLinksFromPage( TitleValue $title, ?array $to = null, $ticket = null ) {
		$db = $this->getDB();

		$where = [
			'gjl_wiki' => $this->mapWiki(),
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
	 * @return array[] set of ['wiki', 'namespace', 'title'] arrays
	 */
	public function getLinksToTarget( TitleValue $target ) {
		if ( !$this->isActive() ) {
			return [];
		}

		$db = $this->getDB();
		$result = $db->newSelectQueryBuilder()
			->select( [
				'gjlw_wiki',
				'gjl_namespace',
				'gjl_title',
			] )
			->from( 'globaljsonlinks' )
			->join( 'globaljsonlinks_target', /* alias: */ null, 'gjl_target=gjlt_id' )
			->join( 'globaljsonlinks_wiki', /* alias: */ null, 'gjl_wiki=gjlw_id' )
			->where( [
				'gjlt_namespace' => $target->getNamespace(),
				'gjlt_title' => $target->getDBkey(),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$links = [];
		foreach ( $result as $row ) {
			$links[] = [
				'wiki' => $row->gjlw_wiki,
				'namespace' => intval( $row->gjl_namespace ),
				'title' => $row->gjl_title,
			];
		}
		return $links;
	}

	/**
	 * Gets list of out-links for the given source page.
	 * @param TitleValue $title linking page
	 * @return string[] set of title keys
	 */
	public function getLinksFromPage( TitleValue $title ) {
		if ( !$this->isActive() ) {
			return [];
		}

		$db = $this->getDB();
		$result = $db->newSelectQueryBuilder()
			->select( [
				'gjlt_namespace',
				'gjlt_title',
			] )
			->from( 'globaljsonlinks' )
			->join( 'globaljsonlinks_target', /* alias: */ null, 'gjl_target=gjlt_id' )
			->join( 'globaljsonlinks_wiki', /* alias: */ null, 'gjl_wiki=gjlw_id' )
			->where( [
				'gjlw_wiki' => $this->wiki,
				'gjl_namespace' => $title->getNamespace(),
				'gjl_title' => $title->getDBkey(),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$links = [];
		foreach ( $result as $row ) {
			if ( $row->gjlt_namespace == NS_DATA ) {
				// @todo either handle more complex namespace scenarios
				// or explicitly forbid that so we don't need the target ns.
				$links[] = $row->gjlt_title;
			}
		}
		return $links;
	}

	/**
	 * Extract the saved JsonConfig usage links from the given parser
	 * output object and update the database to match.
	 *
	 * @param LinksUpdate $linksUpdater
	 */
	public function updateJsonLinks( LinksUpdate $linksUpdater ) {
		if ( !$this->isActive() ) {
			return;
		}

		$title = $linksUpdater->getTitle()->getTitleValue();
		$pages = array_keys(
			$linksUpdater->getParserOutput()->getExtensionData( self::KEY_JSONLINKS ) ?? []
		);
		$existing = $this->getLinksFromPage( $title );

		// Calculate changes
		$added = array_diff( $pages, $existing );
		$removed = array_values( array_diff( $existing, $pages ) );

		// Add new usages and delete removed
		if ( $added ) {
			$this->insertLinks( $title, $added );
		}
		if ( $removed ) {
			$this->deleteLinksFromPage( $title, $removed );
		}
	}
}
