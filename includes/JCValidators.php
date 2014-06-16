<?php
namespace JsonConfig;
use Message;

/**
 * A singleton class to specify that there was no value
 * Class JCMissing
 * @package JsonConfig
 */
final class JCMissing {
	/** Disallow instantiation */
	private function __construct() {}

	/** Get the singleton instance of this class
	 * @return JCMissing
	 */
	public static function get() {
		static $instance = null;
		if ( $instance === null ) {
			$instance = new self();
		}
		return $instance;
	}
}

/**
 * Class JCValidators contains various static validation functions useful for JCKeyValueContent
 * @package JsonConfig
 */
class JCValidators {

	/**
	 * Call one or more validator functions with the given parameters.
	 * $value parameter may be updated.
	 * @param array|callable $validators either a reference to a validator func, or an array of them
	 * @param string $field name of the field, needed by the error messages
	 * @param mixed $value this value may be modified on success
	 * @param JCContent $content
	 * @return bool|Message false if validated, Message object on error
	 */
	public static function run( $validators, $field, & $value, JCContent $content ) {
		if ( $validators ) {
			// function reference in php could be an array with strings
			if ( !is_array( $validators ) || is_string( reset( $validators ) ) ) {
				$validators = array( $validators );
			}
			foreach ( $validators as $validator ) {
				$val = call_user_func( $validator, $field, $value, $content );
				if ( is_object( $val ) && get_class( $val ) === 'Message' ) {
					return $val;
				}
				$value = $val;
			}
		}
		return false;
	}

	/**
	 * Returns a validator function to check if the value is a valid boolean (true/false)
	 * @return callable
	 */
	public static function getBoolValidator() {
		return function ( $fld, $v ) {
			return is_bool( $v ) ? $v : wfMessage( 'jsonconfig-err-bool', $fld );
		};
	}

	/**
	 * Returns a validator function to check if the value is a valid string
	 * @return callable
	 */
	public static function getStrValidator() {
		return function ( $fld, $v ) {
			return is_string( $v ) ? $v : wfMessage( 'jsonconfig-err-string', $fld );
		};
	}

	/**
	 * Returns a validator function to check if the value is a valid integer
	 * @return callable
	 */
	public static function getIntValidator() {
		return function ( $fld, $v ) {
			return is_int( $v ) ? $v : wfMessage( 'jsonconfig-err-integer', $fld );
		};
	}

	/**
	 * Returns a validator function to check if the value is an non-associative array (list)
	 * @return callable
	 */
	public static function getArrayValidator() {
		return function ( $fld, $v ) {
			return JCValidators::isList( $v ) ? $v : wfMessage( 'jsonconfig-err-array', $fld );
		};
	}

	/**
	 * Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function getDictionaryValidator() {
		return function ( $fld, $v ) {
			return JCValidators::isDictionary( $v ) ? $v : wfMessage( 'jsonconfig-err-assoc-array', $fld );
		};
	}

	/**
	 * Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function getUrlValidator() {
		return function ( $fld, $v ) {
			return false !== filter_var( $v, FILTER_VALIDATE_URL ) ? $v
				: wfMessage( 'jsonconfig-err-url', $fld );
		};
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
