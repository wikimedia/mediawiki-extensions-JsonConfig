<?php
namespace JsonConfig;

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
				return JCValidators::isList( $v ) ? $v : wfMessage( 'jsonconfig-err-array', $fld );
			};
		}
		return $validator;
	}

	/**
	 * Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function getDictionaryValidator() {
		static $validator = null;
		if ( $validator === null ) {
			$validator = function ( $fld, $v ) {
				return JCValidators::isDictionary( $v ) ? $v : wfMessage( 'jsonconfig-err-assoc-array', $fld );
			};
		}
		return $validator;
	}

	/**
	 * Helper function to check if the given value is an array,
	 * and all keys are integers (non-associative array)
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isList( $array ) {
		return is_array( $array ) && count( array_filter( array_keys( $array ), 'is_int' ) ) === count( $array );
	}

	/**
	 * Helper function to check if the given value is an array,
	 * and all keys are strings (associative array)
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isDictionary( $array ) {
		return is_array( $array ) && count( array_filter( array_keys( $array ), 'is_string' ) ) === count( $array );
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
