<?php
namespace JsonConfig;

use Message;
use MWException;
use stdClass;

/**
 * This class treats all configs as proper object representation of JSON,
 * and offers a number of primitives to simplify validation on all levels
 * @package JsonConfig
 */
abstract class JCObjContent extends JCContent {
	protected $dataWithDefaults;
	protected $isRootArray = false;
	protected $dataStatus;

	const UNCHECKED = 0;
	const ERROR = 1;
	const CHECKED = 2;
	const DEFAULT_USED = 3;
	const SAME_AS_DEFAULT = 4;

	/**
	 * Override default behavior to include defaults if validation succeeded.
	 *
	 * @return string|bool The raw text, or false if the conversion failed.
	 */
	public function getWikitextForTransclusion() {
		if ( !$this->getStatus()->isGood() ) {
			// If validation failed, return original text
			return parent::getWikitextForTransclusion();
		}
		return \FormatJson::encode( $this->dataWithDefaults, true, \FormatJson::ALL_OK );
	}

	/**
	 * Get configuration data with custom defaults
	 * @return object|array
	 */
	public function getDataWithDefaults() {
		return $this->dataWithDefaults;
	}

	/**
	 * Get status array that recursively describes dataWithDefaults
	 * @return object|array
	 */
	public function getDataStatus() {
		return $this->dataStatus;
	}

	/**
	 * Call this function before performing data validation inside the derived validate()
	 * @return bool if true, validation should be performed, otherwise all checks will be ignored
	 */
	public function initValidation( $data ) {
		if ( !$this->isRootArray && !is_object( $data ) ) {
			$this->getStatus()->fatal( 'jsonconfig-err-root-object-expected' );
		} elseif ( $this->isRootArray && !is_array( $data ) ) {
			$this->getStatus()->fatal( 'jsonconfig-err-root-array-expected' );
		} else {
			$this->dataWithDefaults = $data;
			$this->dataStatus = array();
			return true;
		}
		return false;
	}

	/**
	 * Derived validate() must return the result of this function
	 * @throws \MWException
	 * @return array
	 */
	public function finishValidation() {
		if ( !$this->getStatus()->isGood() ) {
			return $this->getRawData(); // validation failed, do not modify
		}
		$this->dataWithDefaults = $this->recursiveSort( $this->dataWithDefaults, $this->dataStatus );
		return $this->cloneNonDefault( $this->dataWithDefaults, $this->dataStatus );
	}

	public function validate( $data ) {
		if ( $this->initValidation( $data ) ) {
			$this->validateContent();
			return $this->finishValidation();
		}
		return $data;
	}

	/**
	 * Derived classes must implement this method to perform custom validation
	 * using the check(...) calls
	 */
	public abstract function validateContent();

	/**
	 * Use this function to test a field in the data. If missing, the validator(s) will receive JCMissing
	 * singleton as a value, and it will be up to the validator(s) to accept it or not.
	 * @param string|array $path name of the root field to check, or a path to the field in a nested structure.
	 *        Nested path should be in the form of [ 'field-level1', 'field-level2', ... ]. For example, if client
	 *        needs to check validity of the 'value1' in the structure {'key':{'sub-key':['value0','value1']}},
	 *        $field should be set to array('key','sub-key',1).
	 * @param callable|array $validators callback function as defined in JCValidators::run()
	 *        The function should validate given value, and return either Message in case of an error,
	 *        or the value to be used as the result of the field (could be original value or modified)
	 *        or null to use the original value as is.
	 *        If validators is not provided, any value is accepted
	 * @return int status of the validation, or self::UNCHECKED if failed
	 */
	public function test( $path, $validators ) {
		return $this->testOptional( $path, JCMissing::get(), $validators );
	}

	/**
	 * Use this function to test a value, or if the value is missing, use the default value.
	 * The value will be tested with validator(s) if provided, even if it was the default.
	 * @param string|array $path name of the root field to check, or a path to the field in a nested structure.
	 *        Nested path should be in the form of [ 'field-level1', 'field-level2', ... ]. For example, if client
	 *        needs to check validity of the 'value1' in the structure {'key':{'sub-key':['value0','value1']}},
	 *        $field should be set to array('key','sub-key',1).
	 * @param mixed $default value to be used in case field is not found. $default is passed to the validator
	 *        if validation fails. If validation of the default passes, the value is considered optional.
	 * @param callable|array $validators callback function as defined in JCValidators::run()
	 *        The function should validate given value, and return either Message in case of an error,
	 *        or the value to be used as the result of the field (could be original value or modified)
	 *        or null to use the original value as is.
	 *        If validator is not provided, any value is accepted
	 * @return int status of the validation, or self::UNCHECKED if failed
	 * @throws \MWException if $this->initValidation() was not called.
	 */
	public function testOptional( $path, $default, $validators = null ) {

		if ( !$this->getStatus()->isOK() ) {
			return self::ERROR; // skip all validation in case of a fatal error
		}
		if ( $this->dataWithDefaults === null ) {
			throw new MWException( 'This function should only be called inside the validateContent() override' );
		}

		//
		// Status logic: $status is an array of { 'field': { 'field': FLAG, ... }}, where 'field' could also be an int
		// When parsing $path, go through all fields in path:
		// If field exists in $status:
		//    status value is an array:
		//      last field in path - error - do not check parent after child
		//      non-last field in path - do nothing
		//    status value is a flag:
		//      last field in path - we are rechecking same field
		//        UNCHECKED - replace with new flag
		//        all other - do nothing
		//      non-last field in path:
		//        UNCHECKED - replace with an array
		//        CHECKED - replace with an array
		//        DEFAULT_USED - do nothing
		//        SAME_AS_DEFAULT - do nothing
		// else - if field does not exist in $status:
		//     last field in path: set the value to the flag value
		//     non-last field in path: set status to array()

		$newStatus = self::ERROR;
		$statusRef = & $this->dataStatus;
		$dataRef = & $this->dataWithDefaults; // Current data container
		$path = (array) $path;
		$fld = null; // Name of the sub-field in $dataRef
		$fldPath = ''; // For error reporting, path to the current field, e.g. 'fld/3/blah'
		$unsetField = null; // If we are appending a new value, and default fails, unset this field
		$unsetDataRef = null; // If we are appending a new value, and default fails, unset field in this data
		$originalStatusRef = null; // Restore this Ref to $originalStatusVal
		$originalStatusVal = null;
		while( $path ) {
			$fld = array_shift( $path );
			if ( is_int( $fld ) ) {
				$fldPath .= '[' . $fld . ']';
			} else {
				$fldPath .= $fldPath !== '' ? ( '/' . $fld ) : $fld;
			}
			$newStatus = self::ERROR;
			if ( is_string( $fld ) && !is_object( $dataRef ) ) {
				$this->addValidationError( wfMessage( 'jsonconfig-err-object-expected', $fldPath ) );
				break;
			} elseif ( is_int( $fld ) ) {
				if( !is_array( $dataRef ) ) {
					$this->addValidationError( wfMessage( 'jsonconfig-err-array-expected', $fldPath ) );
					break;
				}
				if ( count( $dataRef ) < $fld ) { // Allow existing index or index+1 for appending last item
					throw new MWException( "List index is too large at '$fldPath'. Index may not exceed list size." );
				}
			}

			if ( is_array( $statusRef ) && array_key_exists( $fld, $statusRef ) ) {
				//
				// we have already checked this sub-path, no need to dup-check
				//
				$newStatus = self::CHECKED;
				$statusRef = & $statusRef[$fld];
				if ( !$path && is_array( $statusRef ) ) {
					throw new MWException( "The whole field '$fldPath' cannot be checked after checking its sub-field" );
				}
				if ( is_object( $dataRef ) ? !property_exists( $dataRef, $fld ) : !array_key_exists( $fld, $dataRef ) ) {
					throw new MWException( 'Logic: Status is set, but value is missing' );
				}
				if ( is_object( $dataRef ) ) {
					$dataRef = & $dataRef->$fld;
				} else {
					$dataRef = & $dataRef[$fld];
				}
			} else {
				//
				// We never went down this path or this path was checked as a whole
				// Check that field exists, and is not case-duplicated
				//
				// check for other casing of the field name
				$foundFld = false;
				foreach ( $dataRef as $k => $v ) {
					if ( 0 === strcasecmp( $k, $fld ) ) {
						if ( $foundFld ) {
							$this->addValidationError( wfMessage( 'jsonconfig-duplicate-field', $fldPath ) );
							break;
						}
						$foundFld = $k;
					}
				}
				if ( $foundFld ) {
					// Field found
					$newStatus = $path ? array() : self::CHECKED;
					self::normalizeField( $dataRef, $foundFld, $fld );
					if ( is_object( $dataRef ) ) {
						$dataRef = & $dataRef->$fld;
					} else {
						$dataRef = & $dataRef[$fld];
					}
				} else {
					// Field not found, use default
					if ( $unsetField === null ) {
						$unsetField = $fld;
						$unsetDataRef = & $dataRef;
					}
					$newStatus = $path ? array() : self::DEFAULT_USED;
					if ( is_string( $fld ) ) {
						$dataRef->$fld = $path ? new stdClass() : $default;
						$dataRef = & $dataRef->$fld;
					} else {
						$dataRef[$fld] = $path ? array() : $default;
						$dataRef = & $dataRef[$fld];
					}
				}
				if ( $originalStatusRef === null ) {
					$originalStatusRef = & $statusRef;
					$originalStatusVal = $statusRef;
					if ( !is_array( $statusRef ) ) {
						$statusRef = array(); // we have checked value as a whole, checking sub-values
					}
				}
				$statusRef[$fld] = $newStatus;
				$statusRef = & $statusRef[$fld];
			}
		}

		if ( $validators !== null && $newStatus !== self::ERROR ) {
			$isRequired = $newStatus === self::DEFAULT_USED;
			$err = JCValidators::run( $validators, $fldPath ? : '/', $dataRef, $this );
			if ( $err ) {
				if ( !$isRequired ) {
					// User supplied value, so we don't know if the value is required or not
					// if $default passes validation, original value was optional
					$isRequired = (bool)JCValidators::run( $validators, $fldPath ? : '/', $default, $this );
				}
				$this->addValidationError( $err, !$isRequired );
				$newStatus = self::ERROR;
//			} elseif ( $dataRef === JCMissing::get() ) {
//			@ fixme: if missing is returned, remove it from the data
			}
		}
		if ( $newStatus === self::CHECKED ) {
			// Check if the value is the same as default - use a cast to array hack to compare objects
			if ( ( is_object( $dataRef ) && is_object( $default ) && (array)$dataRef === (array)$default ) ||
				 ( !is_object( $default ) && $dataRef === $default )
			) {
				$newStatus = self::SAME_AS_DEFAULT;
			}
		}
		if ( $newStatus !== self::ERROR ) {
			$statusRef = $newStatus;
		} else {
			//
			// Validation has failed, recover original state of the data & status objects
			//
			if ( $originalStatusRef !== null ) {
				$originalStatusRef = $originalStatusVal;
			}
			if ( $unsetField !== null ) {
				// This value did not exist originally, unset whatever we have added
				if ( is_array( $unsetDataRef ) ) {
					unset( $unsetDataRef[$unsetField] );
				} else {
					unset( $unsetDataRef->$unsetField );
				}
			}
		}
		return $newStatus;
	}

	/**
	 * Recursively add values from value2 into value1
	 * @param array|object $value1
	 * @param array|object|null $value2
	 * @throws \MWException
	 * @return array|object
	 */
	function recursiveMerge( $value1, $value2 ) {
		if ( $value2 === null ) {
			return $value1;
		} elseif ( is_array( $value1 ) ) {
			if ( !self::isList( $value1 ) || !self::isList( $value2 ) ) {
				throw new MWException( 'Unable to merge two non-assoc arrays' );
			}
			return array_merge( $value1, $value2 );
		} elseif ( is_object( $value1 ) ) {
			if ( !is_object( $value2 ) ) {
				throw new MWException( 'Unable to merge object with a non-object' );
			}
			$merged = new stdClass();
			foreach ( $value1 as $k => $v ) {
				$merged->$k = $v;
			}
			foreach ( $value2 as $k => $v ) {
				if ( isset( $value1->$k ) ) {
					$merged->$k = self::recursiveMerge( $value1->$k, $v );
				} else {
					$merged->$k = $v;
				}
			}
			return $merged;
		} else {
			throw new MWException( 'Unable to merge two values' );
		}
	}

	/**
	 * Recursively reorder values to be in the order they were checked in
	 * In other words - sort them in the order they appear in the status
	 * @param array|object $data
	 * @param array $status
	 * @return array|object
	 */
	function recursiveSort( $data, $status ) {
		$isList = null;
		$newData = null;
		foreach ( $status as $fld => $subStatus ) {
			if ( $isList === null ) {
				// if $status[0] is an integer, assume this is a list, otherwise - stdClass
				$isList = is_int( $fld );
				if ( $isList ) {
					break; // Handle lists later
				}
				$newData = new stdClass();
			}
			$newData->$fld = is_array( $subStatus ) ? $this->recursiveSort( $data->$fld, $subStatus ) : $data->$fld;
			unset( $data->$fld );
		}

		if ( $isList === false ) {
			// For objects, copy the unchecked values as is
			foreach ( $data as $fld => $val ) {
				$newData->$fld = $val;
			}
			return $newData;
		}
		if ( $isList === true && is_array( $status ) ) {
			// For lists, perform sub-value sorting in place if those values have statuses
			foreach ( $data as $fld => & $val ) {
				if ( array_key_exists( $fld, $status ) ) {
					$val = $this->recursiveSort( $val, $status[$fld] );
				}
			}
		}
		return $data;
	}

	/**
	 * Recursively copies the non-default values from the data
	 * @param array|object $data
	 * @param array $status
	 * @return array|object
	 */
	function cloneNonDefault( $data, $status ) {
		$isList = is_array( $data );
		$newData = $isList ? array() : new stdClass();
		foreach ( $data as $fld => $subData ) {
			$val = $subData;
			if ( array_key_exists( $fld, $status ) ) {
				$subStatus = $status[$fld];
				if ( $subStatus === self::DEFAULT_USED ) {
					continue;
				}
				if ( is_array( $subStatus ) ) {
					// Only need to filter if we have checked sub-values
					$val = $this->cloneNonDefault( $val, $subStatus );
				}
			}
			if ( $isList ) {
				$newData[$fld] = $val;
			} else {
				$newData->$fld = $val;
			}
		}
		return $newData;
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
	 * Helper function to check if the given value is an array, and all keys are integer (non-associative array)
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isList( $array ) {
		return is_array( $array ) && count( $array ) === count( array_filter( array_keys( $array ), 'is_int' ) );
	}

	/**
	 * Helper function to check if the given value is an array, and all keys are string (associative array)
	 * @param array $array array to check
	 * @return bool
	 */
	public static function isDictionary( $array ) {
		return is_array( $array ) && 0 === count( array_filter( array_keys( $array ), 'is_int' ) );
	}

	/** Helper function to rename a field on an object/array
	 * @param array|stdClass $data
	 * @param string $oldName
	 * @param string $newName
	 */
	private static function normalizeField( $data, $oldName, $newName ) {
		if ( $oldName !== $newName ) { // key had different casing, rename it to canonical
			if ( is_object( $data ) ) {
				$tmp = $data->$oldName;
				unset( $data->$oldName );
				$data->$newName = $tmp;
			} else {
				$tmp = $data[$oldName];
				unset( $data[$oldName] );
				$data[$newName] = $tmp;
			}
		}
	}
}
