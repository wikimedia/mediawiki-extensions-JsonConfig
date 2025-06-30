<?php

namespace JsonConfig;

use InvalidArgumentException;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\StubObject\StubUserLang;
use stdClass;

/**
 * Various useful utility functions (all static)
 */
class JCUtils {

	/**
	 * Uses wfLogWarning() to report an error.
	 * All complex arguments are escaped with FormatJson::encode()
	 * @param string $msg
	 * @param mixed|array $vals
	 * @param array $query
	 */
	public static function warn( $msg, $vals, $query = [] ) {
		if ( !is_array( $vals ) ) {
			$vals = [ $vals ];
		}
		if ( $query ) {
			foreach ( $query as $k => &$v ) {
				if ( stripos( $k, 'password' ) !== false ) {
					$v = '***';
				}
			}
			$vals['query'] = $query;
		}
		$isFirst = true;
		foreach ( $vals as $k => $v ) {
			$msg .= $isFirst ? ': ' : ', ';
			$isFirst = false;
			if ( is_string( $k ) ) {
				$msg .= $k . '=';
			}
			$msg .= is_scalar( $v ) ? $v : FormatJson::encode( $v );
		}
		wfLogWarning( $msg );
	}

	/**
	 * Helper function to check if the given value is an array,
	 * and all keys are integers (non-associative array)
	 * @param array $value array to check
	 * @return bool
	 */
	public static function isList( $value ) {
		return is_array( $value ) &&
			count( array_filter( array_keys( $value ), 'is_int' ) ) === count( $value );
	}

	/**
	 * Helper function to check if the given value is an array,
	 * and all keys are strings (associative array)
	 * @param array $value array to check
	 * @return bool
	 */
	public static function isDictionary( $value ) {
		return is_array( $value ) &&
			count( array_filter( array_keys( $value ), 'is_string' ) ) === count( $value );
	}

	/**
	 * Helper function to check if the given value is an array and if each value in it is a string
	 * @param array $array array to check
	 * @return bool
	 */
	public static function allValuesAreStrings( $array ) {
		return is_array( $array )
			&& count( array_filter( $array, 'is_string' ) ) === count( $array );
	}

	/** Helper function to check if the given value is a valid string no longer than maxlength,
	 * that it has no tabs or new line chars, and that it does not begin or end with spaces
	 * @param string $str
	 * @param int $maxlength
	 * @return bool
	 */
	public static function isValidLineString( $str, $maxlength ) {
		return is_string( $str ) && mb_strlen( $str ) <= $maxlength &&
			!preg_match( '/^\s|[\r\n\t]|\s$/', $str );
	}

	/**
	 * Converts an array representing path to a field into a string in 'a/b/c[0]/d' format
	 * @param array $fieldPath
	 * @return string
	 */
	public static function fieldPathToString( array $fieldPath ) {
		$res = '';
		foreach ( $fieldPath as $fld ) {
			if ( is_int( $fld ) ) {
				$res .= '[' . $fld . ']';
			} elseif ( is_string( $fld ) ) {
				$res .= $res !== '' ? ( '/' . $fld ) : $fld;
			} else {
				throw new InvalidArgumentException(
					'Unexpected field type, only strings and integers are allowed'
				);
			}
		}
		return $res === '' ? '/' : $res;
	}

	/**
	 * Recursively copies values from the data, converting JCValues into the actual values
	 * @param mixed|JCValue $data
	 * @param bool $skipDefaults if true, will clone all items except those marked as default
	 * @return mixed
	 */
	public static function sanitize( $data, $skipDefaults = false ) {
		if ( $data instanceof JCValue ) {
			$value = $data->getValue();
			if ( $skipDefaults && $data->defaultUsed() ) {
				return is_array( $value ) ? [] : ( is_object( $value ) ? (object)[] : null );
			}
		} else {
			$value = $data;
		}
		return self::sanitizeRecursive( $value, $skipDefaults );
	}

	/**
	 * @param mixed $data
	 * @param bool $skipDefaults
	 * @return mixed
	 */
	private static function sanitizeRecursive( $data, $skipDefaults ) {
		if ( !is_array( $data ) && !is_object( $data ) ) {
			return $data;
		}
		if ( is_array( $data ) ) {
			// do not filter lists - only subelements if they were checked
			foreach ( $data as &$valRef ) {
				if ( $valRef instanceof JCValue ) {
					/** @var JCValue $valRef */
					$valRef = self::sanitizeRecursive( $valRef->getValue(), $skipDefaults );
				}
			}
			return $data;
		}
		$result = (object)[];
		foreach ( $data as $fld => $val ) {
			if ( $val instanceof JCValue ) {
				/** @var JCValue $val */
				if ( $skipDefaults === true && $val->defaultUsed() ) {
					continue;
				}
				$result->$fld = self::sanitizeRecursive( $val->getValue(), $skipDefaults );
			} else {
				$result->$fld = $val;
			}
		}
		return $result;
	}

	/**
	 * Returns true if each of the array's values is a valid language code
	 * @param array $arr
	 * @return bool
	 */
	public static function isListOfLangs( $arr ) {
		$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
		return count( $arr ) === count( array_filter( $arr, static function ( $v ) use ( $languageNameUtils ) {
			return is_string( $v ) && $languageNameUtils->isValidBuiltInCode( $v );
		} ) );
	}

	/**
	 * Returns true if the array is a valid key->value localized nonempty array
	 * @param array $arr
	 * @param int $maxlength
	 * @return bool
	 */
	public static function isLocalizedArray( $arr, $maxlength ) {
		if ( is_array( $arr ) &&
			$arr &&
			self::isListOfLangs( array_keys( $arr ) )
		) {
			$validStrCount = count( array_filter( $arr, function ( $str ) use ( $maxlength ) {
				return self::isValidLineString( $str, $maxlength );
			} ) );
			if ( $validStrCount === count( $arr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find a message in a dictionary for the given language,
	 * or use language fallbacks if message is not defined.
	 * @param stdClass $map Dictionary of languageCode => string
	 * @param Language|StubUserLang $lang
	 * @param bool|string $defaultValue if non-false, use this value in case no fallback and no 'en'
	 * @return string message from the dictionary or "" if nothing found
	 */
	public static function pickLocalizedString( stdClass $map, $lang, $defaultValue = false ) {
		$langCode = $lang->getCode();
		if ( property_exists( $map, $langCode ) ) {
			return $map->$langCode;
		}
		foreach ( $lang->getFallbackLanguages() as $l ) {
			if ( property_exists( $map, $l ) ) {
				return $map->$l;
			}
		}
		// If fallbacks fail, check if english is defined
		if ( property_exists( $map, 'en' ) ) {
			return $map->en;
		}

		// We have a custom default, return that
		if ( $defaultValue !== false ) {
			return $defaultValue;
		}

		// Return first available value, or an empty string
		// There might be a better way to get the first value from an object
		$map = (array)$map;
		return reset( $map ) ? : '';
	}

	/**
	 * Cache may return raw JSON strings, and internally we may work with raw
	 * objects, so rehydrate those to standard content objects.
	 * @param JCTitle $title
	 * @param JCContent|stdClass|string|false $content
	 * @param bool $thorough whether to include full validation on input
	 * @return Status<JCContent>
	 */
	public static function hydrate( JCTitle $title, $content, $thorough = false ): Status {
		$handler = new JCContentHandler( $title->getConfig()->model );
		if ( $content instanceof stdClass ) {
			$content = json_encode( $content );
		}
		if ( is_string( $content ) ) {
			$content = $handler->unserializeContent( $content, null, $thorough );
		}
		if ( $content instanceof JCContent ) {
			return Status::newGood( $content );
		}
		if ( $content === false ) {
			// JCCache->get returns false for missing page.
			return Status::newFatal( 'jsonconfig-transform-missing-data', $title->getDbKey() );
		}
		return Status::newFatal( 'jsonconfig-transform-invalid-data', $title->getDbKey() );
	}

}
