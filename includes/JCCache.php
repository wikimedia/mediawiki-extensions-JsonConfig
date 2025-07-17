<?php
namespace JsonConfig;

use MediaWiki\MediaWikiServices;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Represents a json blob on a remote wiki.
 * Handles retrieval (via HTTP) and memcached caching.
 */
class JCCache {
	/** @var JCTitle */
	private $titleValue;
	/** @var string */
	private $key;
	/** @var WANObjectCache */
	private $cache;
	/** @var bool|string|JCContent */
	private $content = null;

	/** @var int number of seconds to keep the value in cache */
	private $cacheExpiration;

	/**
	 * ** DO NOT USE directly - call JCSingleton::getContent() instead. **
	 *
	 * @param JCTitle $titleValue
	 */
	public function __construct( JCTitle $titleValue ) {
		$this->titleValue = $titleValue;
		$conf = $this->titleValue->getConfig();
		$flRev = $conf->flaggedRevs;
		$this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$keyArgs = [
			'JsonConfig',
			MediaWikiServices::getInstance()->getMainConfig()->get( 'JsonConfigCacheKeyPrefix' ),
			$conf->cacheKey,
			$flRev === null ? '' : ( $flRev ? 'T' : 'F' ),
			$titleValue->getNamespace(),
			$titleValue->getDBkey(),
		];
		if ( $conf->isLocal ) {
			$this->key = $this->cache->makeKey( ...$keyArgs );
		} else {
			$this->key = $this->cache->makeGlobalKey( ...$keyArgs );
		}
		$this->cacheExpiration = $conf->cacheExp;
	}

	/**
	 * Retrieves content.
	 * @return string|JCContent|false Content string/object or false if irretrievable.
	 */
	public function get() {
		if ( $this->content === null ) {
			if ( $this->disableJsonConfigCache() ) {
				$this->content = $this->fetchContent();
				return $this->content;
			}

			$value = $this->cache->getWithSetCallback(
				$this->key,
				$this->cacheExpiration,
				function ( $oldValue, &$ttl ) {
					// If the content is invalid, store an empty string to prevent repeated attempts
					$content = $this->fetchContent() ?: '';

					if ( !$content ) {
						$ttl = 10;
					}

					if ( !is_string( $content ) ) {
						$content = $content->getText();
					}

					return $content;
				}
			);

			if ( $value === '' ) {
				$this->content = false;
			} else {
				$this->content = $value;
			}
		}

		return $this->content;
	}

	/**
	 * Returns true if caching is disabled for JsonConfig
	 *
	 * TODO: Use this method in upcoming refactorings.
	 *
	 * @return bool
	 */
	private function disableJsonConfigCache(): bool {
		return MediaWikiServices::getInstance()->getMainConfig()->get( 'JsonConfigDisableCache' );
	}

	/**
	 * Retrieves the content of the JSON config if the page
	 * exists on the local wiki or over HTTP and set the content
	 * property (on-demand).
	 *
	 * @return string|JCContent|false
	 */
	private function fetchContent() {
		if ( $this->titleValue->getConfig()->store ) {
			return $this->loadLocal(); // Get it from the local wiki
		}

		return $this->loadRemote(); // Get it from HTTP
	}

	/**
	 * Delete any cached information related to this config.
	 */
	public function resetCache() {
		if ( !$this->disableJsonConfigCache() ) {
			// Delete the old value: this will propagate over WANCache
			$this->cache->delete( $this->key );
		}
	}

	/**
	 * Retrieve the config from the local storage
	 *
	 * @return bool|string|JCContent
	 */
	protected function loadLocal() {
		// @fixme @bug handle flagged revisions
		$result = MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromLinkTarget( $this->titleValue )
			->getContent();
		if ( !$result ) {
			$result = false; // Keeping consistent with other usages
		} elseif ( !( $result instanceof JCContent ) ) {
			if ( $result->getModel() === CONTENT_MODEL_WIKITEXT ) {
				// If this is a regular wiki page, allow it to be parsed as a json config
				$result = $result->getNativeData();
			} else {
				wfLogWarning( "The locally stored wiki page '$this->titleValue' has " .
					"unsupported content model'" );
				$result = false;
			}
		}
		return $result;
	}

	/**
	 * Retrieve the content over HTTP from another wiki
	 *
	 * @return bool|string
	 */
	private function loadRemote() {
		do {
			$result = false;
			$conf = $this->titleValue->getConfig();
			$remote = $conf->remote;
			$apiUtils = MediaWikiServices::getInstance()->getService( 'JsonConfig.ApiUtils' );
			// @phan-suppress-next-line PhanTypeExpectedObjectPropAccessButGotNull
			$req = $apiUtils->initApiRequestObj( $remote->url, $remote->username ?? null, $remote->password ?? null );
			if ( !$req ) {
				break;
			}
			$ns = $conf->nsName ?: MediaWikiServices::getInstance()
				->getNamespaceInfo()
				->getCanonicalName( $this->titleValue->getNamespace() );
			$articleName = $ns . ':' . $this->titleValue->getText();
			$flrevs = $conf->flaggedRevs;
			// if flaggedRevs is false, get wiki page directly,
			// otherwise get the flagged state first
			$res = $this->getPageFromApi( $articleName, $req, $flrevs === false
					? [
						'action' => 'query',
						'titles' => $articleName,
						'prop' => 'revisions',
						'rvprop' => 'content',
						'rvslots' => 'main',
						'continue' => '',
					]
					: [
						'action' => 'query',
						'titles' => $articleName,
						'prop' => 'info|flagged',
						'continue' => '',
					] );
			if ( $res !== false &&
				( $flrevs === null || ( $flrevs === true && array_key_exists( 'flagged', $res ) ) )
			) {
				// If there is a stable flagged revision present, use it.
				// else - if flaggedRevs is null, use the latest revision that exists
				// otherwise, fail because flaggedRevs is true,
				// which means we require rev to be flagged
				$res = $this->getPageFromApi( $articleName, $req, [
					'action' => 'query',
					'revids' => array_key_exists( 'flagged', $res )
						? $res['flagged']['stable_revid'] : $res['lastrevid'],
					'prop' => 'revisions',
					'rvprop' => 'content',
					'rvslots' => 'main',
					'continue' => '',
				] );
			}
			if ( $res === false ) {
				break;
			}

			$result = $res['revisions'][0]['slots']['main']['*'] ?? false;
			if ( $result === false ) {
				break;
			}
		} while ( false );

		return $result;
	}

	/** Given a legal set of API parameters, return page from API
	 * @param string $articleName title name used for warnings
	 * @param \MWHttpRequest $req logged-in session
	 * @param array $query
	 * @return bool|mixed
	 */
	private function getPageFromApi( $articleName, $req, $query ) {
		$apiUtils = MediaWikiServices::getInstance()->getService( 'JsonConfig.ApiUtils' );
		$revInfo = $apiUtils->callApi( $req, $query, 'get remote JsonConfig' );
		if ( $revInfo === false ) {
			return false;
		}
		if ( !isset( $revInfo['query']['pages'] ) ) {
			JCUtils::warn( 'Unrecognizable API result', [ 'title' => $articleName ], $query );
			return false;
		}
		$pages = $revInfo['query']['pages'];
		if ( !is_array( $pages ) || count( $pages ) !== 1 ) {
			JCUtils::warn( 'Unexpected "pages" element', [ 'title' => $articleName ], $query );
			return false;
		}
		$pageInfo = reset( $pages ); // get the only element of the array
		if ( isset( $pageInfo['missing'] ) ) {
			return false;
		}
		return $pageInfo;
	}
}
