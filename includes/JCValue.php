<?php
namespace JsonConfig;

use Message;
use Exception;

/**
 * A class with validation state that wraps each accessed value in the JCObjContent::validationData
 * @package JsonConfig
 */
final class JCValue {

	private $status;
	private $value;
	private $sameAsDefault = false;
	private $defaultUsed = false;
	private $error = false;

	/** Value has not been checked */
	const UNCHECKED = 0;
	/** Value was explicitly checked (might be an error) */
	const CHECKED = 1;
	/** field is missing in the data, but is being explicitly tested for.
	 * This value should never be stored in JCObjContent::validationData.
	 * Setting this value for any field in validator will delete it. */
	const MISSING = 2;
	/** field was not explicitly tested, but it was listed as a parent of one of the tested fields */
	const VISITED = 3;

	/** @param int $status
	 * @param mixed $value
	 */
	function __construct( $status, $value ) {
		$this->status = $status;
		$this->value = $value;
	}

	/** @return mixed */
	public function & getValue() {
		return $this->value;
	}

	public function setValue( $value, $status = null ) {
		$this->value = $value;
		if ( $status !== null ) {
			$this->status( $status );
		} elseif ( $this->isMissing() ) {
			// Convenience - if we are setting a new value, assume we are setting a default
			$this->status( self::UNCHECKED );
			$this->defaultUsed( true );
		}
	}

	public function status( $status = null ) {
		$val = $this->status;
		if ( $status !== null ) {
			$this->status = $status;
		}
		return $val;
	}

	public function sameAsDefault( $sameAsDefault = null ) {
		$val = $this->sameAsDefault;
		if ( $sameAsDefault !== null ) {
			$this->sameAsDefault = $sameAsDefault;
		}
		return $val;
	}

	public function defaultUsed( $defaultUsed = null ) {
		$val = $this->defaultUsed;
		if ( $defaultUsed !== null ) {
			$this->defaultUsed = $defaultUsed;
		}
		return $val;
	}

	public function isMissing() {
		return $this->status === self::MISSING;
	}

	public function isUnchecked() {
		return $this->status === self::UNCHECKED;
	}

	/** Helper function - same arguments as wfMessage, or true if message was already added.
	 * false clears this message status, and null returns current state without changing it
	 * @param null|bool|string $key message id, or if bool, sets/removes error status
	 * @param array $fieldPath path to the erroneous field. Will be converted to a a/b/c[0]/d style
	 * @return bool|Message
	 */
	public function error( $key = null, $fieldPath = null /*...*/ ) {
		if ( is_bool( $key ) ) {
			$this->error = $key;
		} elseif ( $key !== null ) {
			$args = func_get_args();
			if ( is_array( $fieldPath ) ) {
				// Convert field path to a printable string
				$args[1] = JCUtils::fieldPathToString( $fieldPath );
			}
			$this->error = call_user_func_array( 'wfMessage', $args );
		}
		return $this->error;
	}

	/**
	 * @param string|int $fld
	 * @param mixed $value
	 * @throws Exception
	 */
	public function setField( $fld, $value ) {
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			$this->value->$fld = $value;
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			$this->value[$fld] = $value;
		} else {
			throw new Exception( 'Type mismatch for field ' . $fld );
		}
	}

	/**
	 * @param string|int $fld
	 * @throws \Exception
	 * @return mixed
	 */
	public function deleteField( $fld ) {
		$tmp = null;
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			if ( isset( $this->value->$fld ) ) {
				$tmp = $this->value->$fld;
				unset( $this->value->$fld );
			}
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			if ( isset( $this->value[$fld] ) ) {
				$tmp = $this->value[$fld];
				unset( $this->value[$fld] );
			}
		} else {
			throw new Exception( 'Type mismatch for field ' . $fld );
		}
		return $tmp;
	}

	/**
	 * @param string|int $fld
	 * @throws \Exception
	 * @return bool
	 */
	public function fieldExists( $fld ) {
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			return property_exists( $this->value, $fld );
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			return array_key_exists( $fld, $this->value );
		}
		throw new Exception( 'Type mismatch for field ' . $fld );
	}

	/**
	 * @param string|int $fld
	 * @throws \Exception
	 * @return mixed
	 */
	public function getField( $fld ) {
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			return $this->value->$fld;
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			return $this->value[$fld];
		}
		throw new Exception( 'Type mismatch for field ' . $fld );
	}
}
