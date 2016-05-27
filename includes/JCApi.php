<?php
namespace JsonConfig;

use ApiBase;
use MWNamespace;

/**
 * Allows JsonConfig to be manipulated via API
 */
class JCApi extends ApiBase {

	private static function addStatusConf( $conf ) {
		// explicitly list values to avoid accidental exposure of private data
		$res = array(
			'model' => $conf->model,
			'namespace' => $conf->namespace,
			'nsName' => $conf->nsName,
			'nsTalk' => isset( $conf->nsTalk ) && $conf->nsTalk ? $conf->nsTalk : 'default',
			'isLocal' => $conf->isLocal,
			'cacheExp' => $conf->cacheExp,
			'cacheKey' => $conf->cacheKey,
			'flaggedRevs' => $conf->flaggedRevs,
		);
		if ( isset( $conf->remote ) ) {
			$res['remote'] = array(
				'url' => $conf->remote->url,
				'username' => $conf->remote->username !== '',
				'password' => $conf->remote->password !== '',
			);
		}
		if ( isset( $conf->store ) ) {
			$res['store'] = array(
				'cacheNewValue' => $conf->store->cacheNewValue,
				'notifyUrl' => $conf->store->notifyUrl,
				'notifyUsername' => $conf->store->notifyUsername !== '',
				'notifyPassword' => $conf->store->notifyPassword !== '',
			);
		}
		return $res;
	}

	public function execute() {
		$result = $this->getResult();

		$params = $this->extractRequestParams();
		$command = $params['command'];

		switch ( $command ) {
			case 'status':
				$this->getMain()->setCacheMaxAge( 1 * 30 ); // seconds
				$this->getMain()->setCacheMode( 'public' );

				global $wgJsonConfigModels;
				$result->addValue( null, 'models', $wgJsonConfigModels );

				$data = array();
				foreach ( JCSingleton::getTitleMap() as $ns => $confs ) {
					$vals = array();
					foreach ( $confs as $conf ) {
						$vals[] = self::addStatusConf( $conf );
					}
					$data[$ns] = $vals;
				}
				if ( $data ) {
					$result->setIndexedTagName( $data, 'ns' );
				}
				$result->addValue( null, 'titleMap', $data );
				break;

			case 'reset':
			case 'reload':

				$this->getMain()->setCacheMaxAge( 1 ); // seconds
				$this->getMain()->setCacheMode( 'private' );
				if ( !$this->getUser()->isAllowed( 'jsonconfig-flush' ) ) {
					$this->dieUsage( "Must be authenticated with jsonconfig-flush right to use this API",
						'login', 401 );
				}
				if ( !isset( $params['title'] ) ) {
					$this->dieUsage( 'Parameter "title" is required for this command', 'badparam-title' );
				}

				// Manual title parsing - each title must have a non-localized namespace, but an integer can be used
				$ns = null;
				$parts = explode( ':', $params['title'], 2 );
				if ( count( $parts ) === 2 ) {
					if ( is_numeric( $parts[0] ) ) {
						$ns = intval( $parts[0] );
						if ( (string)$ns !== $parts[0] ) {
							$ns = null;
						}
					} else {
						$ns = \MWNamespace::getCanonicalIndex( strtolower( $parts[0] ) );
					}
				}
				// @todo/fixme: use parseTitle's title string parsing instead
				// Need to rework it to check for invalid characters (e.g. '#'), normalization ('_' vs ' '),
				// extra whitespaces at either end.
				if ( $ns === null || !array_key_exists( $ns, JCSingleton::getTitleMap() ) ||
				     ( $t = \Title::newFromText( $parts[1], $ns ) ) === null ||
				     !( $jct = JCSingleton::parseTitle( $t ) )
				) {
					$this->dieUsage( 'The "title" parameter must be in form NS:Title, where NS is either an integer or a canonical ' .
					                 'namespace name. In either case, namespace must be defined as part of JsonConfig configuration',
						'badparam-titles' );
				}

				/** @var JCTitle $jct */
				$handler = new JCContentHandler( $jct->getConfig()->model );

				if ( isset( $params['content'] ) && $params['content'] !== '' ) {
					if ( $command !== 'reload ' ) {
						$this->dieUsage( 'The "content" parameter may only be used with command=reload',
							'badparam-content' );
					}
					$text = $params['content'];
					$content = $handler->unserializeContent( $text, null, true );
				} else {
					$content = false;
				}

				$jc = new JCCache( $jct, $content );
				if ( $command === 'reset' ) {
					$jc->resetCache( false ); // clear cache
				} elseif ( $content ) {
					$jc->resetCache( true ); // set new value in cache
				} else {
					$jc->get(); // gets content from the default source and cache
				}

				break;
		}
	}

	public function getAllowedParams() {
		return array(
			'command' => array(
				ApiBase::PARAM_DFLT => 'status',
				ApiBase::PARAM_TYPE => array(
					'status',
					'reset',
					'reload',
				)
			),
			'title' => '',
			'content' => '',
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getParamDescription() {
		return array(
			'command' => array(
				'What sub-action to perform on JsonConfig:',
				'  status - shows JsonConfig configuration',
				'  reset  - clears configurations from cache. Requires title parameter and jsonconfig-reset right',
				'  reload - reloads and caches configurations from config store. Requires title parameter and jsonconfig-reset right',
			),
			'title' => 'title to process',
			'content' => 'For command=reload, use this content instead',
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return 'Allows direct access to JsonConfig subsystem';
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getExamples() {
		return array(
			'api.php?action=jsonconfig&format=jsonfm',
			'api.php?action=jsonconfig&command=reset&title=Zero:TEST&format=jsonfm',
			'api.php?action=jsonconfig&command=reload&title=Zero:TEST&format=jsonfm',
		);
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=jsonconfig&format=jsonfm'
				=> 'apihelp-jsonconfig-example-1',
			'action=jsonconfig&command=reset&title=Zero:TEST&format=jsonfm'
				=> 'apihelp-jsonconfig-example-2',
			'action=jsonconfig&command=reload&title=Zero:TEST&format=jsonfm'
				=> 'apihelp-jsonconfig-example-3',
		);
	}
}
