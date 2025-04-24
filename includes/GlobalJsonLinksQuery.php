<?php

namespace JsonConfig;

use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleValue;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * A helper class to query the globaljsonlinks_* tables
 * Based off the equivalent class in GlobalUsage
 *
 */
class GlobalJsonLinksQuery {
	/** The number of internal |-separated chunks in the offset strings, for validation */
	private const OFFSET_PARTS = 5;

	/** @var int */
	private $limit = 50;
	/** @var array */
	private $offset;
	/** @var bool */
	private $hasMore = false;
	/** @var array[][][] */
	private $result;
	/** @var bool|null */
	private $reversed = false;

	/** @var int[] namespace ID(s) desired */
	private $filterNamespaces;

	/** @var string[] sites desired */
	private $filterSites;

	/**
	 * @var TitleValue
	 */
	private $target;

	/** @var stdClass|null */
	private $lastRow;

	/**
	 * @var IDatabase
	 */
	private $db;

	/**
	 * @var string[]
	 */
	private $canonicalNamespaces;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param NamespaceInfo $namespaceInfo
	 * @param TitleValue $target target page; doesn't necessarily have to be in Data:
	 *        namespace, as upcoming filter support will increase the types of deps
	 *        a Data: load can invoke.
	 */
	public function __construct( IConnectionProvider $connectionProvider,
			NamespaceInfo $namespaceInfo, TitleValue $target ) {
		$this->db = $connectionProvider->getPrimaryDatabase( 'virtual-globaljsonlinks' );
		$this->canonicalNamespaces = $namespaceInfo->getCanonicalNamespaces();
		$this->target = $target;
		$this->offset = [];
	}

	/**
	 * Set the offset parameter and validate that it has the correct
	 * number of parts. An invalid offset will be ignored.
	 *
	 * @param string $offset offset
	 * @param bool|null $reversed True if this is the upper offset
	 * @return bool true on success
	 */
	public function setOffset( $offset, $reversed = null ) {
		if ( $reversed !== null ) {
			$this->reversed = $reversed;
		}

		if ( !is_array( $offset ) ) {
			$offset = explode( '|', $offset );
		}

		if ( self::validateOffsetArray( $offset ) ) {
			$this->offset = $offset;
			return true;
		} else {
			return false;
		}
	}

	private static function validateOffsetArray( array $offset ): bool {
		return count( $offset ) == self::OFFSET_PARTS;
	}

	public function hasOffset(): bool {
		return self::validateOffsetArray( $this->offset );
	}

	/**
	 * Return the offset set by the user
	 *
	 * @return string offset
	 */
	public function getOffsetString() {
		return implode( '|', $this->offset );
	}

	/**
	 * Is the result reversed
	 *
	 * @return bool
	 */
	public function isReversed() {
		return $this->reversed;
	}

	/**
	 * Returns the string used for continuation
	 *
	 * @return string
	 *
	 */
	public function getContinueString() {
		if ( $this->hasMore() ) {
			return implode( "|", [
				$this->lastRow->gjlt_namespace,
				$this->lastRow->gjlt_title,
				$this->lastRow->gjlw_wiki,
				$this->lastRow->gjl_namespace,
				$this->lastRow->gjl_title,
			] );
		} else {
			return '';
		}
	}

	/**
	 * Set the maximum amount of items to return. Capped at 500.
	 *
	 * @param int $limit The limit
	 */
	public function setLimit( $limit ) {
		$this->limit = min( $limit, 500 );
	}

	/**
	 * Returns the user set limit
	 * @return int
	 */
	public function getLimit() {
		return $this->limit;
	}

	/**
	 * Return results only for these namespaces.
	 * @param int[] $namespaces numeric namespace IDs
	 */
	public function filterNamespaces( $namespaces ) {
		$this->filterNamespaces = $namespaces;
	}

	/**
	 * Return results only for these sites.
	 * @param string[] $sites wiki site names
	 */
	public function filterSites( $sites ) {
		$this->filterSites = $sites;
	}

	/**
	 * Executes the query
	 */
	public function execute() {
		/* Construct the SQL query */
		$queryBuilder = $this->db->newSelectQueryBuilder()
			->select( [
				'gjlt_namespace',
				'gjlt_title',
				'gjlw_wiki',
				'gjlw_namespace',
				'gjlw_namespace_text',
				'gjl_namespace',
				'gjl_title',
			] )
			->from( 'globaljsonlinks_target' )
			->join( 'globaljsonlinks', /* alias: */ null, 'gjl_target=gjlt_id' )
			->join( 'globaljsonlinks_wiki', /* alias: */ null, 'gjlw_id=gjl_wiki' )
			// Select an extra row to check whether we have more rows available
			->limit( $this->limit + 1 )
			->caller( __METHOD__ );

		$this->applyFilters( $queryBuilder );
		$this->applyOffset( $queryBuilder );
		$this->processResult( $queryBuilder );
	}

	private function applyFilters( SelectQueryBuilder $queryBuilder ) {
		// Add target page
		$queryBuilder->where( [
			'gjlt_namespace' => $this->target->getNamespace(),
			'gjlt_title' => $this->target->getDbKey(),
		] );

		if ( $this->filterNamespaces ) {
			$queryBuilder->andWhere( [ 'gjl_namespace' => $this->filterNamespaces ] );
		}

		if ( $this->filterSites ) {
			$queryBuilder->andWhere( [ 'gjlw_wiki' => $this->filterSites ] );
		}
	}

	private function applyOffset( SelectQueryBuilder $queryBuilder ) {
		// Set the continuation condition
		if ( $this->hasOffset() ) {
			$offsets = [
				'gjlt_namespace' => intval( $this->offset[0] ),
				'gjlt_title' => $this->offset[1],
				'gjlw_wiki' => $this->offset[2],
				'gjl_namespace' => intval( $this->offset[3] ),
				'gjl_title' => $this->offset[4],
			];
			// Check which limit we got in order to determine which way to traverse rows
			if ( $this->reversed ) {
				// Reversed traversal; do not include offset row
				$op = '<';
				$dir = SelectQueryBuilder::SORT_DESC;
			} else {
				// Normal traversal; include offset row
				$op = '>=';
				$dir = SelectQueryBuilder::SORT_ASC;
			}
			$queryBuilder->orderBy(
				array_keys( $offsets ),
				$dir
			);
			$queryBuilder->andWhere( $this->db->buildComparison( $op, $offsets ) );
		}
	}

	private function processResult( SelectQueryBuilder $queryBuilder ) {
		$res = $queryBuilder->fetchResultSet();

		// Always return the result in the same order; regardless whether reversed was specified
		// reversed is really only used to determine from which direction the offset is
		$rows = [];
		$count = 0;
		$this->hasMore = false;
		foreach ( $res as $row ) {
			$count++;
			if ( $count > $this->limit ) {
				// We've reached the extra row that indicates that there are more rows
				$this->hasMore = true;
				$this->lastRow = $row;
				break;
			}
			$rows[] = $row;
		}
		if ( $this->reversed ) {
			$rows = array_reverse( $rows );
		}

		// Build the result array
		$this->result = [];
		foreach ( $rows as $row ) {
			$this->result["$row->gjlt_namespace:$row->gjlt_title"][$row->gjlw_wiki][] = [
				'wiki' => $row->gjlw_wiki,
				'namespaceText' => $row->gjlw_namespace_text ??
					$this->canonicalNamespaces[ intval( $row->gjl_namespace ) ],
				'title' => new TitleValue( intval( $row->gjl_namespace ), $row->gjl_title ),
				'target' => new TitleValue( intval( $row->gjlt_namespace ), $row->gjlt_title ),
			];
		}
	}

	/**
	 * Returns the result set. The result is a 4 dimensional array
	 * (file, wiki, page), whose items are arrays with keys:
	 *   - target: linked Data: page dbkey
	 *   - namespace: Page namespace id
	 *   - title: Unprefixed page title
	 *   - wiki: Wiki id
	 *
	 * @return array Result set
	 */
	public function getResult() {
		return $this->result;
	}

	/**
	 * Returns a 3 dimensional array with the result of the first file. Useful
	 * if only one page was queried.
	 *
	 * For further information see documentation of getResult()
	 *
	 * @return array Result set
	 */
	public function getSinglePageResult() {
		if ( $this->result ) {
			return current( $this->result );
		} else {
			return [];
		}
	}

	/**
	 * Returns whether there are more results
	 *
	 * @return bool
	 */
	public function hasMore() {
		return $this->hasMore;
	}

	/**
	 * Returns the result length
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->result );
	}
}
