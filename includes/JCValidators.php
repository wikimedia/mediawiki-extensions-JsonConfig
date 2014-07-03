<?php
namespace JsonConfig;

/**
 * Class JCValidators contains various static validation functions
 * @package JsonConfig
 */
class JCValidators {

	/** Call one or more validator functions with the given parameters.
	 * Validator parameters:  function ( JCValue $jcv, string $fieldPath, JCContent $content )
	 * Validator should update $jcv object with any errors it finds by using error() function. Validator
	 * may also change the value or set default/same-as-default flags.
	 * Setting status to JCValue::MISSING will delete this value (but not its parent)
	 * @param array $validators an array of validator function closures
	 * @param JCValue $value value to validate, modify, and change status of
	 * @param array $path path to the field, needed by the error messages
	 * @param JCContent $content
	 */
	public static function run( array $validators, JCValue $value, array $path, JCContent $content ) {
		if ( $validators ) {
			foreach ( $validators as $validator ) {
				if ( !call_user_func( $validator, $value, $path, $content ) ) {
					break;
				}
			}
		}
	}

	/** Returns a validator function to check if the value is a valid boolean (true/false)
	 * @return callable
	 */
	public static function isBool() {
		return function ( JCValue $v, array $path ) {
			if ( !is_bool( $v->getValue() ) ) {
				$v->error( 'jsonconfig-err-bool', $path );
				return false;
			}
			return true;
		};
	}

	/** Returns a validator function to check if the value is a valid string
	 * @return callable
	 */
	public static function isString() {
		return function ( JCValue $v, array $path ) {
			if ( !is_string( $v->getValue() ) ) {
				$v->error( 'jsonconfig-err-string', $path );
				return false;
			}
			return true;
		};
	}

	/** Returns a validator function to check if the value is a valid integer
	 * @return callable
	 */
	public static function isInt() {
		return function ( JCValue $v, array $path ) {
			if ( !is_int( $v->getValue() ) ) {
				$v->error( 'jsonconfig-err-integer', $path );
				return false;
			}
			return true;
		};
	}

	/** Returns a validator function to check if the value is an non-associative array (list)
	 * @return callable
	 */
	public static function isList() {
		return function ( JCValue $v, array $path ) {
			if ( !JCUtils::isList( $v->getValue() ) ) {
				$v->error( 'jsonconfig-err-array', $path );
				return false;
			}
			return true;
		};
	}

	/** Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function isDictionary() {
		return function ( JCValue $v, array $path ) {
			if ( !is_object( $v->getValue() ) ) {
				$v->error( 'jsonconfig-err-assoc-array', $path );
				return false;
			}
			return true;
		};
	}

	/** Returns a validator function to check if the value is an associative array
	 * @return callable
	 */
	public static function isUrl() {
		return function ( JCValue $v, array $path ) {
			if ( false === filter_var( $v->getValue(), FILTER_VALIDATE_URL ) ) {
				$v->error( 'jsonconfig-err-url', $path );
				return false;
			}
			return true;
		};
	}

	/** Returns a validator function that will substitute missing value with default
	 * @param mixed $default value to use in case field is not present
	 * @param bool $validateDefault if true, the default value will be verified by the validators
	 * @return callable
	 */
	public static function useDefault( $default, $validateDefault = true ) {
		return function ( JCValue $v ) use ( $default, $validateDefault ) {
			if ( $v->isMissing() ) {
				$v->setValue( $default );
				return $validateDefault;
			}
			return true;
		};
	}

	/** Returns a validator function that informs that this field should be deleted
	 * @return callable
	 */
	public static function deleteField() {
		return function ( JCValue $v ) {
			$v->status( JCValue::MISSING );
			// continue executing validators - there could be a custom one that changes it further
			return true;
		};
	}
}
