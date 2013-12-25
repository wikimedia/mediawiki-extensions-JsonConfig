<?php
namespace JsonConfig;

use Message;
use MWException;

/**
 * Class JCKeyValueContent that treats all configs as a one level key => value pair dictionaries,
 * and offers a number of primitives to simplify validation.
 * @package JsonConfig
 */
abstract class JCKeyValueContent extends JCContent {
	protected $dataWithDefaults;
	protected $defaultFields;
	protected $sameAsDefault;
	protected $unknownFields;

	/**
	 * Override default behavior to include defaults
	 *
	 * @return string|false: the raw text, or null if the conversion failed
	 */
	public function getWikitextForTransclusion() {
		if ( !$this->getStatus()->isGood() ) {
			// If validation failed, return original text
			return parent::getWikitextForTransclusion();
		}
		$data = array_merge( $this->dataWithDefaults, $this->unknownFields );

		return \FormatJson::encode( $data, true, \FormatJson::ALL_OK );
	}

	/**
	 * Get configuration data with custom defaults
	 * @return array
	 */
	public function getDataWithDefaults() {
		return $this->dataWithDefaults;
	}

	/**
	 * Get an array of fields that were not present in the configuration
	 * @return array
	 */
	public function getDefaultFields() {
		return $this->defaultFields;
	}

	/**
	 * Get an array of fields that have the same value as default
	 * @return array
	 */
	public function getSameAsDefault() {
		return $this->sameAsDefault;
	}

	/**
	 * Get a dictionary of all unrecognized fields with their values
	 * @return Array
	 */
	public function getUnknownFields() {
		return $this->unknownFields;
	}

	/**
	 * Call this function before performing data validation inside the derived validate()
	 */
	public function initValidation( $data ) {
		$this->unknownFields = $data;
		$this->defaultFields = array();
		$this->sameAsDefault = array();
	}

	/**
	 * Derived validate() must return the result of this function
	 * @return array
	 */
	public function finishValidation() {
		// Rearrange data: first - everything that was checked, followed by all unrecognized fields
		$data = $this->dataWithDefaults;
		foreach ( $this->defaultFields as $fld => $val ) {
			unset( $data[$fld] );
		}

		return array_merge( $data, $this->unknownFields );
	}

	/**
	 * Derrived classes must override this method to perform custom validation:
	 * - call initValidation($data)
	 * - perform validation using check(...) calls
	 * - return the result of the finishValidation() call
	 * @param $data
	 * @throws MWException if not overriden in the derived class
	 * @return mixed
	 */
	public function validate( $data ) {
		throw new MWException( 'Derived class must override this function' );
	}

	/**
	 * Use this function to perform all custom validation of the configuration
	 * @param string $field name of the field to check
	 * @param array|string|int|bool $default value to be used in case field is not found
	 * @param callable $validator callback function($value, $this)
	 *        The function should validate given value, and return either Message in case of an error,
	 *        or the value to be used as the result of the field (could be original value or modified)
	 *        or null to use the original value as is.
	 *        If validator is not provided, any value is accepted
	 * @throws MWException if $this->initValidation() was not called.
	 */
	public function check( $field, $default, $validator = null ) {
		$value = null;
		$valueFound = false;
		$duplicates = array();

		$data = &$this->unknownFields;
		if ( $data === null ) {
			throw new MWException( 'Implementation of the validate( $data ) function must first call ' .
				'"$this->initValidation( $data );", use check() to validate, ' .
				'and end with "return $this->finishValidation();"' );
		}
		// check for exact field name match
		if ( array_key_exists( $field, $data ) ) {
			$duplicates[] = $field;
			$value = $data[$field];
			$valueFound = true;
			unset( $data[$field] );
		}
		// check for other casing of the field name
		foreach ( $data as $k => $v ) {
			if ( 0 === strcasecmp( $k, $field ) ) {
				$duplicates[] = $k;
				$value = $data[$k];
				$valueFound = true;
				unset( $data[$k] );
			}
		}
		if ( count( $duplicates ) > 1 ) {
			$this->addValidationError( wfMessage( 'jsonconfig-duplicate-field' ) );

			return;
		}
		if ( !$valueFound ) {
			$value = $default;
		}
		if ( $validator !== null ) {
			$value = call_user_func( $validator, $field, $value, $this );
			if ( is_object( $value ) && get_class( $value ) === 'Message' ) {
				// @todo: isOptional should be determined by passing default into the validator
				$this->addValidationError( $value, $default !== null );

				return;
			}
		}
		$this->dataWithDefaults[$field] = $value;
		if ( !$valueFound ) {
			$this->defaultFields[$field] = true;
		} elseif ( $value === $default ) {
			$this->sameAsDefault[$field] = true;
		}
	}

	/**
	 * @param Message $error
	 * @param bool $isOptional
	 */
	public function addValidationError( Message $error, $isOptional = false ) {
		$text = $error->plain();
		if ( $isOptional ) {
			$text .= ' ' . wfMessage( 'jsonconfig-optional-field' )->plain();
		}
		$this->getStatus()->error( $text );
	}

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
