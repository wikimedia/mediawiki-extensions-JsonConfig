<?php
namespace JsonConfig;

use FormatJson;
use Http;
use MWException;
use MWNamespace;
use Title;

/**
 * Represents a json blob on a remote wiki.
 * Handles retrieval (via HTTP) and memcached caching.
 */
class JCCache {
	private $title, $key, $cache, $conf;
	private $isCached = false;

	/** @var bool|string|JCContent */
	private $content = false;

	/** @var int number of seconds to keep the value in cache */
	private $cacheExpiration;

	/**
	 * Constructor for JCCache
	 * ** DO NOT USE directly - call JCSingleton::getCachedStore() instead. **
	 *
	 * @param Title $title
	 * @param array $conf
	 */
	function __construct( $title, $conf ) {
		global $wgJsonConfigCacheKeyPrefix;
		$this->title = $title;
		$this->conf = $conf;
		$flrev = $conf['flaggedrevs'];
		$key = implode( ':', array(
			'JsonConfig',
			$wgJsonConfigCacheKeyPrefix,
			( $flrev === null ? '' : ( $flrev ? 'T' : 'F' ) ),
			$title->getNamespace(),
			$title->getDBkey() ) );
		if ( $conf['islocal'] ) {
			$key = wfMemcKey( $key );
		}
		if ( array_key_exists( 'cacheexp', $conf ) ) {
			$this->cacheExpiration = $conf['cacheexp'];
		} else {
			$this->cacheExpiration = 60 * 60 * 24;
		}
		$this->key = $key;
		$this->cache = wfGetCache( CACHE_ANYTHING );
	}

	/**
	 * Retrieves content.
	 * @return string|JCContent|false: Content string/object or false if irretrievable.
	 */
	public function get() {
		if ( !$this->isCached ) {
			$value = $this->memcGet(); // Get content from the memcached
			if ( $value === false ) {
				if ( $this->conf['storehere'] ) {
					$this->loadLocal(); // Get it from the local wiki
				} else {
					$this->loadRemote(); // Get it from HTTP
				}
				$this->memcSet(); // Save result to memcached
			} elseif ( $value === '' ) {
				$this->content = false; // Invalid ID was cached
			} else {
				$this->content = $value; // Content was cached
			}
			$this->isCached = true;
		}

		return $this->content;
	}

	/**
	 * Retrieves content from memcached.
	 * @return string|bool Carrier config or false if not in cache.
	 */
	private function memcGet() {
		global $wgJsonConfigDisableCache;

		return $wgJsonConfigDisableCache ? false : $this->cache->get( $this->key );
	}

	/**
	 * Store $this->content in memcached.
	 * If the content is invalid, store an empty string to prevent repeated attempts
	 */
	private function memcSet() {
		global $wgJsonConfigDisableCache;
		if ( $wgJsonConfigDisableCache ) {
			return true;
		}
		$value = $this->content;
		if ( !$value ) {
			$value = '';
		} elseif ( !is_string( $value ) ) {
			$value = $value->getNativeData();
		}

		return $this->cache->set( $this->key, $value, $this->cacheExpiration );
	}

	/**
	 * Delete any cached information related to this config
	 */
	public function resetCache() {
		global $wgJsonConfigDisableCache;
		if ( !$wgJsonConfigDisableCache ) {
			$this->cache->delete( $this->key );
		}
	}

	/**
	 * Retrieves the config from the local storage, and sets $this->content to the content object or false
	 */
	private function loadLocal() {
		wfProfileIn( __METHOD__ );
		// @fixme @bug handle flagged revisions
		$result = \WikiPage::factory( $this->title )->getContent();
		if ( !$result ) {
			$result = false; // Keeping consistent with other usages
		} elseif ( !( $result instanceof JCContent ) ) {
			if ( $result->getModel() === CONTENT_MODEL_WIKITEXT ) {
				// If this is a regular wiki page, allow it to be parsed as a json config
				$result = $result->getNativeData();
			} else {
				wfLogWarning( "The locally stored wiki page '$this->title' has unsupported content model'" );
				$result = false;
			}
		}
		$this->content = $result;
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Retrieves the config using HTTP and sets $this->content to string or false
	 */
	private function loadRemote() {
		wfProfileIn( __METHOD__ );
		$ns = $this->conf['remotensname']
			? $this->conf['remotensname']
			: MWNamespace::getCanonicalName( $this->title->getNamespace() );
		$title = $ns . ':' . $this->title->getText();
		$flrevs = $this->conf['flaggedrevs'];
		if ( $flrevs === false ) {
			$query = array(
				'format' => 'json',
				'action' => 'query',
				'titles' => $title,
				'prop' => 'revisions',
				'rvprop' => 'content',
			);
			$result = $this->getPageFromApi( $query );
		} else {
			$query = array(
				'format' => 'json',
				'action' => 'query',
				'titles' => $title,
				'prop' => 'info|flagged',
			);
			$result = $this->getPageFromApi( $query );
			if ( $result !== false &&
				( $flrevs === null || array_key_exists( 'flagged', $result ) )
			) {
				// If there is a stable flagged revision present, use it,
				// otherwise use the latest revision that exists (unless flaggedRevs is true)
				$revId = array_key_exists( 'flagged', $result ) ?
					$result['flagged']['stable_revid'] :
					$result['lastrevid'];
				$query = array(
					'format' => 'json',
					'action' => 'query',
					'revids' => $revId,
					'prop' => 'revisions',
					'rvprop' => 'content',
				);
				$result = $this->getPageFromApi( $query );
			}
		}
		if ( $result !== false ) {
			$result = isset( $result['revisions'][0]['*'] ) ? $result['revisions'][0]['*'] : false;
		}
		$this->content = $result;
		wfProfileOut( __METHOD__ );
	}

	/** Given a legal set of API parameters, return page from API
	 * @param array $query
	 * @throws MWException
	 * @return bool|mixed
	 */
	private function getPageFromApi( $query ) {
		$apiUri = wfAppendQuery( $this->conf['url'], $query );
		// @fixme: decide timeout value
		$resp = Http::get( $apiUri, 3 ); // a few seconds is enough - otherwise we have bigger problems
		if ( !$resp ) {
			wfLogWarning( "Failed to get remote config '$query'" );

			return false;
		}
		$revInfo = FormatJson::decode( $resp, true );
		if ( isset( $revInfo['warnings'] ) ) {
			wfLogWarning( "Waring from API call '$query': " . print_r( $revInfo['warnings'], true ) );
		}
		if ( !isset( $revInfo['query']['pages'] ) ) {
			wfLogWarning( "Unrecognizable API result for '$query'" );

			return false;
		}
		$pages = $revInfo['query']['pages'];
		if ( !is_array( $pages ) || count( $pages ) !== 1 ) {
			wfLogWarning( "Unexpected 'pages' element for '$query'" );

			return false;
		}
		$pageInfo = reset( $pages ); // get the only element of the array
		if ( isset( $revInfo['missing'] ) ) {
			wfLogWarning( "Config page does not exist for '$query'" );

			return false;
		}

		return $pageInfo;
	}
}
