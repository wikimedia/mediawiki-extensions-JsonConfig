<?php
namespace JsonConfig;

use FormatJson;
use MWHttpRequest;
use MWNamespace;
use TitleValue;

/**
 * Represents a json blob on a remote wiki.
 * Handles retrieval (via HTTP) and memcached caching.
 */
class JCCache {
	private $titleValue, $key, $cache, $conf;
	private $isCached = false;

	/** @var bool|string|JCContent */
	private $content = false;

	/** @var int number of seconds to keep the value in cache */
	private $cacheExpiration;

	/**
	 * Constructor for JCCache
	 * ** DO NOT USE directly - call JCSingleton::getCachedStore() instead. **
	 *
	 * @param TitleValue $titleValue
	 * @param array $conf
	 */
	function __construct( $titleValue, $conf ) {
		global $wgJsonConfigCacheKeyPrefix;
		$this->titleValue = $titleValue;
		$this->conf = $conf;
		$flrev = $conf['flaggedrevs'];
		$key = implode( ':', array(
			'JsonConfig',
			$wgJsonConfigCacheKeyPrefix,
			( $flrev === null ? '' : ( $flrev ? 'T' : 'F' ) ),
			$titleValue->getNamespace(),
			$titleValue->getDBkey() ) );
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
		$title = \Title::newFromTitleValue( $this->titleValue );
		$result = \WikiPage::factory( $title )->getContent();
		if ( !$result ) {
			$result = false; // Keeping consistent with other usages
		} elseif ( !( $result instanceof JCContent ) ) {
			if ( $result->getModel() === CONTENT_MODEL_WIKITEXT ) {
				// If this is a regular wiki page, allow it to be parsed as a json config
				$result = $result->getNativeData();
			} else {
				wfLogWarning( "The locally stored wiki page '$this->titleValue' has unsupported content model'" );
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
		$req = $this->initApiRequestObj();
		if ( $req !== false ) {
			$ns = $this->conf['nsname']
				? $this->conf['nsname']
				: MWNamespace::getCanonicalName( $this->titleValue->getNamespace() );
			$articleName = $ns . ':' . $this->titleValue->getText();
			$flrevs = $this->conf['flaggedrevs'];
			if ( $flrevs === false ) {
				$query = array(
					'format' => 'json',
					'action' => 'query',
					'titles' => $articleName,
					'prop' => 'revisions',
					'rvprop' => 'content',
				);
				$result = $this->getPageFromApi( $articleName, $req, $query );
			} else {
				$query = array(
					'format' => 'json',
					'action' => 'query',
					'titles' => $articleName,
					'prop' => 'info|flagged',
				);
				$result = $this->getPageFromApi( $articleName, $req, $query );
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
					$result = $this->getPageFromApi( $articleName, $req, $query );
				}
			}
			if ( $result !== false ) {
				if ( isset( $result['revisions'][0]['*'] ) ) {
					$result = $result['revisions'][0]['*'];
				} else {
					$result = false;
					self::warn( 'Unable to get config content',
						array( 'title' => $articleName, 'result' => $result ) );
				}
			}
			$this->content = $result;
		}

		wfProfileOut( __METHOD__ );
	}

	/** Init HTTP request object to make requests to the API, and login
	 * @return bool|\CurlHttpRequest|\PhpHttpRequest
	 */
	private function initApiRequestObj() {
		$conf = $this->conf;
		$apiUri = wfAppendQuery( $conf['url'], array( 'format' => 'json' ) );
		$options = array(
			'timeout' => 3,
			'connectTimeout' => 'default',
			'method' => 'POST',
		);
		$req = MWHttpRequest::factory( $apiUri, $options );

		if ( $conf['username'] !== false && $conf['password'] !== false ) {
			$postData = array(
				'action' => 'login',
				'lgname' => $conf['username'],
				'lgpassword' => $conf['password'],
			);
			$req->setData( $postData );
			$status = $req->execute();
			$runCount = 1;

			if ( $status->isGood() ) {
				$res = json_decode( $req->getContent(), true );
				if ( isset( $res['login']['token'] ) ) {
					$postData['lgtoken'] = $res['login']['token'];
					$req->setData( $postData );
					$status = $req->execute();
					$runCount++;
				}
			}
			if ( !$status->isGood() ) {
				self::warn( "Failed to login",
					array( 'run' => $runCount, 'url' => $conf['url'], 'user' => $conf['username'], 'status' => $status ) );
				// Ignore "OK"/"Failed" state - in case login failed, we still attempt to get data
			}
		}
		return $req;
	}

	/** Given a legal set of API parameters, return page from API
	 * @param string $articleName title name used for warnings
	 * @param \CurlHttpRequest|\PhpHttpRequest $req logged-in session
	 * @param array $query
	 * @return bool|mixed
	 */
	private function getPageFromApi( $articleName, $req, $query ) {
		$req->setData( $query );
		$status = $req->execute();
		if ( !$status->isGood() ) {
			self::warn( 'Failed to get remote config',
				array( 'title' => $articleName, 'status' => $status, 'query' => $query ) );
			return false;
		}
		$revInfo = FormatJson::decode( $req->getContent(), true );
		if ( isset( $revInfo['warnings'] ) ) {
			self::warn( 'Warning from API call',
				array( 'title' => $articleName, 'query' => $query, 'warnings' => $revInfo['warnings'] ) );
		}
		if ( !isset( $revInfo['query']['pages'] ) ) {
			self::warn( 'Unrecognizable API result', array( 'title' => $articleName, 'query' => $query ) );
			return false;
		}
		$pages = $revInfo['query']['pages'];
		if ( !is_array( $pages ) || count( $pages ) !== 1 ) {
			self::warn( 'Unexpected "pages" element', array( 'title' => $articleName, 'query' => $query ) );
			return false;
		}
		$pageInfo = reset( $pages ); // get the only element of the array
		if ( isset( $revInfo['missing'] ) ) {
			self::warn( 'Config page does not exist', array( 'title' => $articleName, 'query' => $query ) );
			return false;
		}
		return $pageInfo;
	}

	/**
	 * Uses wfLogWarning() to report an error. All complex arguments are escaped with FormatJson::encode()
	 * @param string $msg
	 */
	private static function warn( $msg, $vals ) {
		if ( !is_array( $vals ) ) {
			$vals = array( $vals );
		}
		$isFirst = true;
		foreach ( $vals as $k => $v ) {
			if ( $isFirst ) {
				$isFirst = false;
				$msg .= ': ';
			} else {
				$msg .= ', ';
			}
			if ( is_string( $k ) ) {
				$msg .= $k . '=';
			}
			if ( is_string( $v ) || is_int( $v ) ) {
				$msg .= $v;
			} else {
				$msg .= FormatJson::encode( $v );
			}
		}
		wfLogWarning( $msg );
	}
}
