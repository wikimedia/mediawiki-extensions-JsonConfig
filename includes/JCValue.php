<?php
namespace JsonConfig;

use InvalidArgumentException;
use MediaWiki\Message\Message;

/**
 * A class with validation state that wraps each accessed value in the JCObjContent::validationData
 */
final class JCValue {

	/** @var int */
	private $status;
	/** @var mixed */
	private $value;
	/** @var bool|null */
	private $sameAsDefault = false;
	/** @var bool|null */
	private $defaultUsed = false;
	/** @var bool|Message */
	private $error = false;

	/** Value has not been checked */
	public const UNCHECKED = 0;
	/** Value was explicitly checked (might be an error) */
	public const CHECKED = 1;
	/** field is missing in the data, but is being explicitly tested for.
	 * This value should never be stored in JCObjContent::validationData.
	 * Setting this value for any field in validator will delete it.
	 */
	public const MISSING = 2;
	/** field was not explicitly tested, but it was listed as a parent of one of the tested fields */
	public const VISITED = 3;

	/**
	 * @param int $status
	 * @param mixed $value
	 */
	public function __construct( $status, $value ) {
		$this->status = $status;
		$this->value = $value;
	}

	/** @return mixed */
	public function & getValue() {
		return $this->value;
	}

	/**
	 * @param mixed $value
	 * @param int|null $status
	 */
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

	/**
	 * @param int|null $status
	 * @return int|null
	 */
	public function status( $status = null ) {
		$val = $this->status;
		if ( $status !== null ) {
			$this->status = $status;
		}
		return $val;
	}

	/**
	 * @param bool|null $sameAsDefault
	 * @return bool|null
	 */
	public function sameAsDefault( $sameAsDefault = null ) {
		$val = $this->sameAsDefault;
		if ( $sameAsDefault !== null ) {
			$this->sameAsDefault = $sameAsDefault;
		}
		return $val;
	}

	/**
	 * @param bool|null $defaultUsed
	 * @return bool|null
	 */
	public function defaultUsed( $defaultUsed = null ) {
		$val = $this->defaultUsed;
		if ( $defaultUsed !== null ) {
			$this->defaultUsed = $defaultUsed;
		}
		return $val;
	}

	/**
	 * @return bool
	 */
	public function isMissing() {
		return $this->status === self::MISSING;
	}

	/**
	 * @return bool
	 */
	public function isUnchecked() {
		return $this->status === self::UNCHECKED;
	}

	/** Helper function - same arguments as wfMessage, or true if message was already added.
	 * false clears this message status, and null returns current state without changing it
	 * @param null|bool|string $key message id, or if bool, sets/removes error status
	 * @param array|null $fieldPath path to the erroneous field. Will be converted to a/b/c[0]/d style
	 * @param mixed ...$params
	 * @return bool|Message
	 */
	public function error( $key = null, $fieldPath = null, ...$params ) {
		if ( is_bool( $key ) ) {
			$this->error = $key;
		} elseif ( $key !== null ) {
			if ( is_array( $fieldPath ) ) {
				// Convert field path to a printable string
				$fieldPath = JCUtils::fieldPathToString( $fieldPath );
			}
			$this->error = wfMessage( $key, $fieldPath ?? '', ...$params );
		}
		return $this->error;
	}

	/**
	 * @param string|int $fld
	 * @param mixed $value
	 */
	public function setField( $fld, $value ) {
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			$this->value->$fld = $value;
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			$this->value[$fld] = $value;
		} else {
			throw new InvalidArgumentException( 'Type mismatch for field ' . $fld );
		}
	}

	/**
	 * @param string|int $fld
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
			throw new InvalidArgumentException( 'Type mismatch for field ' . $fld );
		}
		return $tmp;
	}

	/**
	 * @param string|int $fld
	 * @return bool
	 */
	public function fieldExists( $fld ) {
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			return property_exists( $this->value, $fld );
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			return array_key_exists( $fld, $this->value );
		}
		throw new InvalidArgumentException( 'Type mismatch for field ' . $fld );
	}

	/**
	 * @param string|int $fld
	 * @return mixed
	 */
	public function getField( $fld ) {
		if ( is_object( $this->value ) && is_string( $fld ) ) {
			return $this->value->$fld;
		} elseif ( is_array( $this->value ) && ( is_string( $fld ) || is_int( $fld ) ) ) {
			return $this->value[$fld];
		}
		throw new InvalidArgumentException( 'Type mismatch for field ' . $fld );
	}
}
