<?php

namespace JsonConfig;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Status\Status;
use MWHttpRequest;

class JCApiUtils {

	/**
	 * @var HttpRequestFactory
	 */
	private $httpRequestFactory;

	public function __construct( HttpRequestFactory $httpRequestFactory ) {
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/** Init HTTP request object to make requests to the API, and login
	 * @param string|null $url
	 * @param string|null $username
	 * @param string|null $password
	 * @return MWHttpRequest|false
	 */
	public function initApiRequestObj( $url, $username, $password ) {
		if ( $url === null ) {
			return false;
		}
		$apiUri = wfAppendQuery( $url, [ 'format' => 'json' ] );
		$options = [
			'timeout' => 3,
			'connectTimeout' => 'default',
			'method' => 'POST',
		];
		$req = $this->httpRequestFactory->create( $apiUri, $options, __METHOD__ );

		if ( $username !== null && $username !== '' &&
			$password !== null && $password !== ''
		) {
			$tokenQuery = [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'login',
			];
			$query = [
				'action' => 'login',
				'lgname' => $username,
				'lgpassword' => $password,
			];
			$res = $this->callApi( $req, $tokenQuery, 'get login token' );
			if ( $res !== false ) {
				if ( isset( $res['query']['tokens']['logintoken'] ) ) {
					$query['lgtoken'] = $res['query']['tokens']['logintoken'];
					$res = $this->callApi( $req, $query, 'login with token' );
				}
			}
			if ( $res === false ) {
				$req = false;
			} elseif ( !isset( $res['login']['result'] ) ||
				$res['login']['result'] !== 'Success'
			) {
				JCUtils::warn( 'Failed to login', [
						'url' => $url,
						'user' => $username,
						'result' => $res['login']['result'] ?? '???'
				] );
				$req = false;
			}
		}
		return $req;
	}

	/**
	 * Make an API call on a given request object and warn in case of failures
	 * @param MWHttpRequest $req logged-in session
	 * @param array $query api call parameters
	 * @param string $debugMsg extra message for debug logs in case of failure
	 * @return array|false api result or false on error
	 */
	public function callApi( $req, $query, $debugMsg ) {
		$status = $this->callApiStatus( $req, $query, $debugMsg );
		if ( $status->isOk() ) {
			return $status->getValue();
		}
		return false;
	}

	/**
	 * Make an API call on a given request object and warn in case of failures
	 * @param MWHttpRequest $req logged-in session
	 * @param array $query api call parameters
	 * @param string $debugMsg extra message for debug logs in case of failure
	 * @return Status<array> api result or error
	 */
	public function callApiStatus( $req, $query, $debugMsg ): Status {
		$req->setData( $query );
		$status = $req->execute();
		if ( !$status->isGood() ) {
			JCUtils::warn(
				'API call failed to ' . $debugMsg,
				[ 'status' => Status::wrap( $status )->getWikiText() ],
				$query
			);
			return $status;
		}
		$res = FormatJson::decode( $req->getContent(), true );
		if ( isset( $res['warnings'] ) ) {
			JCUtils::warn( 'API call had warnings trying to ' . $debugMsg,
				[ 'warnings' => $res['warnings'] ], $query );
		}
		if ( isset( $res['error'] ) ) {
			JCUtils::warn(
				'API call failed trying to ' . $debugMsg, [ 'error' => $res['error'] ], $query
			);
			return Status::newFatal(
				'jsonconfig-internal-api-error',
				$res['error']['code'] ?? '',
				$res['error']['info'] ?? ''
			);
		}
		return Status::newGood( $res );
	}
}
