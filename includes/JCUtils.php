<?php

namespace JsonConfig;

use FormatJson;
use MWHttpRequest;

/**
 * Various useful utility functions (all static)
 */
class JCUtils {

	/**
	 * Uses wfLogWarning() to report an error. All complex arguments are escaped with FormatJson::encode()
	 * @param string $msg
	 */
	public static function warn( $msg, $vals ) {
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

	/** Init HTTP request object to make requests to the API, and login
	 * @param string $url
	 * @param string $username
	 * @param string $password
	 * @throws \MWException
	 * @return \CurlHttpRequest|\PhpHttpRequest
	 */
	public static function initApiRequestObj( $url, $username, $password ) {
		$apiUri = wfAppendQuery( $url, array( 'format' => 'json' ) );
		$options = array(
			'timeout' => 3,
			'connectTimeout' => 'default',
			'method' => 'POST',
		);
		$req = MWHttpRequest::factory( $apiUri, $options );

		if ( $username && $password ) {
			$postData = array(
				'action' => 'login',
				'lgname' => $username,
				'lgpassword' => $password,
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
					$runCount ++;
				}
			}
			if ( !$status->isGood() ) {
				self::warn( "Failed to login",
					array( 'run' => $runCount, 'url' => $url, 'user' => $username, 'status' => $status ) );
				// Ignore "OK"/"Failed" state - in case login failed, we still attempt to get data
			}
		}
		return $req;
	}

	/**
	 * Make an API call on a given request object and warn in case of failures
	 * @param \CurlHttpRequest|\PhpHttpRequest $req logged-in session
	 * @param array $query api call parameters
	 * @param string $debugMsg extra message for debug logs in case of failure
	 * @return array api result
	 */
	public static function callApi( $req, $query, $debugMsg ) {
		$req->setData( $query );
		$status = $req->execute();
		if ( !$status->isGood() ) {
			self::warn( 'API call failed to ' . $debugMsg,
				array( 'status' => $status, 'query' => $query ) );
			return false;
		}
		$res = FormatJson::decode( $req->getContent(), true );
		if ( isset( $res['warnings'] ) ) {
			self::warn( 'API call had warnings trying to ' . $debugMsg,
				array( 'query' => $query, 'warnings' => $res['warnings'] ) );
		}
		return $res;
	}

	/**
	 * Helper function to check if the given value is an array,
	 * and all keys are integers (non-associative array)
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isList( $array ) {
		return is_array( $array ) &&
		       count( array_filter( array_keys( $array ), 'is_int' ) ) === count( $array );
	}

	/**
	 * Helper function to check if the given value is an array,
	 * and all keys are strings (associative array)
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isDictionary( $array ) {
		return is_array( $array ) &&
		       count( array_filter( array_keys( $array ), 'is_string' ) ) === count( $array );
	}

	/**
	 * Helper function to check if the given value is an array and if each value in it is a string
	 * @param array $array array to check
	 * @return bool
	 */
	public static function allValuesAreStrings( $array ) {
		return is_array( $array ) && count( array_filter( $array, 'is_string' ) ) === count( $array );
	}
}
