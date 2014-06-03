<?php
namespace JsonConfig;

use JsonConfig\JCValidators;
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
	 * @param string $text Json configuration. If null, default content will be inserted instead
	 * @param string $modelId
	 * @param bool $isSaving True if extra validation should be performed
	 */
	public function __construct( $text, $modelId, $isSaving ) {
		$this->useAssocParsing = true;
		parent::__construct( $text, $modelId, $isSaving );
	}

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
			$this->addValidationError( wfMessage( 'jsonconfig-duplicate-field', $field ) );

			return;
		}
		if ( !$valueFound ) {
			$value = $default;
		}
		if ( $validator !== null ) {
			$value = call_user_func( $validator, $field, $value, $this );
			if ( is_object( $value ) && get_class( $value ) === 'Message' ) {
				// if $default passes validation, original value was optional
				$dfltVal = call_user_func( $validator, $field, $default, $this );
				$this->addValidationError( $value, !is_object( $dfltVal ) || get_class( $value ) !== 'Message' );
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


}
