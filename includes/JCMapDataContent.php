<?php
namespace JsonConfig;

use Kartographer\SimpleStyleParser;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\User\User;

class JCMapDataContent extends JCDataContent {

	public function validateContent() {
		parent::validateContent();

		if ( !$this->thorough() ) {
			// We are not doing any modifications to the original, so no need to validate it
			return;
		}

		$this->testOptional( 'zoom', 3, JCValidators::isInt() );
		$this->testOptional( 'latitude', 0, JCValidators::isNumber() );
		$this->testOptional( 'longitude', 0, JCValidators::isNumber() );

		$this->test( 'data', self::isValidData() );

		$this->test( [], JCValidators::noExtraValues() );
	}

	/**
	 * @inheritDoc
	 */
	public function getSafeData( $data ) {
		$parser = MediaWikiServices::getInstance()->getParser();

		// In case the parser hasn't been used yet
		if ( !$parser->getOptions() ) {
			$options = new ParserOptions( new User() );
			$parser->startExternalParse( null, $options, OT_HTML );
		}

		$data = parent::getSafeData( $data );

		$config = MediaWikiServices::getInstance()->getMainConfig();
		$ssp = SimpleStyleParser::newFromParser( $parser, $config );
		$ssp->normalizeAndSanitize( $data->data );

		return $data;
	}

	/**
	 * @return callable
	 */
	private static function isValidData() {
		return static function ( JCValue $v, array $path ) {
			$value = $v->getValue();

			if ( ( !is_object( $value ) && !is_array( $value ) ) ||
				!JCMapDataContent::recursiveWalk( $value, false )
			) {
				$v->error( 'jsonconfig-err-bad-geojson', $path );
				return false;
			}

			// TODO: decide if this is needed. We would have to alter the above code to localize props
			// // Use SimpleStyleParser to verify the data's validity
			// $ssp = new \Kartographer\SimpleStyleParser( MediaWikiServices::getInstance()->getParser() );
			// $status = $ssp->parseObject( $value );
			// if ( !$status->isOK() ) {
			// $v->status( $status );
			// }
			// return $status->isOK();
			return true;
		};
	}

	/**
	 * Recursively walk the geojson to replace localized "title" and "description" values
	 * with the single string corresponding to the $lang language, or if $lang is not set,
	 * validates those values and returns true/false if valid
	 * @param \stdClass|array &$json
	 * @param Language|false $lang
	 * @return bool
	 */
	public static function recursiveWalk( &$json, $lang = false ) {
		if ( is_array( $json ) ) {
			foreach ( $json as &$element ) {
				if ( !self::recursiveWalk( $element, $lang ) ) {
					return false;
				}
			}
		} elseif ( is_object( $json ) ) {
			foreach ( array_keys( get_object_vars( $json ) ) as $prop ) {
				if ( $prop === 'properties' && is_object( $json->properties ) ) {
					if ( !self::isValidStringOrLocalized( $json->properties, 'title', $lang ) ||
						!self::isValidStringOrLocalized( $json->properties, 'description', $lang )
					) {
						return false;
					}
				} elseif ( !self::recursiveWalk( $json->$prop, $lang ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param \stdClass $obj
	 * @param string $property
	 * @param Language|false $lang
	 * @param int $maxlength
	 * @return bool
	 */
	private static function isValidStringOrLocalized( $obj, $property, $lang, $maxlength = 400 ) {
		if ( property_exists( $obj, $property ) ) {
			$value = $obj->$property;
			if ( !$lang ) {
				return is_object( $value ) ? JCUtils::isLocalizedArray( (array)$value, $maxlength )
					: JCUtils::isValidLineString( $value, $maxlength );
			} elseif ( is_object( $value ) ) {
				$obj->$property = JCUtils::pickLocalizedString( $value, $lang );
			}
		}
		return true;
	}

	/** @inheritDoc */
	protected function localizeData( $result, Language $lang ) {
		parent::localizeData( $result, $lang );

		$data = $this->getData();

		if ( isset( $data->zoom ) ) {
			$result->zoom = $data->zoom;
		}
		if ( isset( $data->latitude ) ) {
			$result->latitude = $data->latitude;
		}
		if ( isset( $data->longitude ) ) {
			$result->longitude = $data->longitude;
		}

		$geojson = FormatJson::decode( FormatJson::encode( $data->data, false, FormatJson::ALL_OK ) );
		self::recursiveWalk( $geojson, $lang );

		$result->data = $geojson;
	}

	/** @inheritDoc */
	protected function createDefaultView() {
		return new JCMapDataContentView();
	}
}
