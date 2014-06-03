<?php
namespace JsonConfig;

use Message;
use MWException;
use stdClass;

/**
 * Class JCValidators contains various static validation functions useful for JCKeyValueContent
 * @package JsonConfig
 */
class JCValidators {

	/**
	 * Returns a validator function to check if the value is a valid boolean (true/false)
	 * @return callable
	 */
	public static function getBoolValidator() {
		static $validator = null;
		if ( $validator === null ) {
			$validator = function ( $fld, $v ) {
				return is_bool( $v ) ? $v : wfMessage( 'jsonconfig-err-bool', $fld );
			};
		}

		return $validator;
	}

	/**
	 * Returns a validator function to check if the value is a valid string
	 * @return callable
	 */
	public static function getStrValidator() {
		static $validator = null;
		if ( $validator === null ) {
			$validator = function ( $fld, $v ) {
				return is_string( $v ) ? $v : wfMessage( 'jsonconfig-err-string', $fld );
			};
		}

		return $validator;
	}

	/**
	 * Returns a validator function to check if the value is a valid integer
	 * @return callable
	 */
	public static function getIntValidator() {
		static $validator = null;
		if ( $validator === null ) {
			$validator = function ( $fld, $v ) {
				return is_int( $v ) ? $v : wfMessage( 'jsonconfig-err-integer', $fld );
			};
		}

		return $validator;
	}

	/**
	 * Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function getArrayValidator() {
		static $validator = null;
		if ( $validator === null ) {
			$validator = function ( $fld, $v ) {
				return JCValidators::isArray( $v, false ) ? $v : wfMessage( 'jsonconfig-err-array', $fld );
			};
		}
		return $validator;
	}

	/**
	 * Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function getAssocArrayValidator() {
		static $validator = null;
		if ( $validator === null ) {
			$validator = function ( $fld, $v ) {
				return JCValidators::isArray( $v, true ) ? $v : wfMessage( 'jsonconfig-err-assoc-array', $fld );
			};
		}
		return $validator;
	}

	/**
	 * Helper function to check if the given value is an array, and all keys are either
	 * integer (non-associative array), or strings (associative array)
	 * @param array $array array to check
	 * @param bool $isAssoc true if expecting an associative array
	 * @return bool
	 */
	public static function isArray( $array, $isAssoc ) {
		if ( !is_array( $array ) ) {
			return false;
		}
		$strCount = count( array_filter( array_keys( $array ), 'is_string' ) );

		return ( $isAssoc && $strCount === count( $array ) ) || ( !$isAssoc && $strCount === 0 );
	}

	/**
	 * Helper function to check if the given value is an array and if each value in it is a string
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isArrayOfStrings( $array ) {
		return is_array( $array ) && count( $array ) === count( array_filter( $array, 'is_string' ) );
	}
}
