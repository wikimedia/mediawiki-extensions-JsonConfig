<?php

namespace JsonConfig;

use Html;
use Language;
use stdClass;

/**
 * @package JsonConfig
 */
abstract class JCDataContent extends JCObjContent {

	/**
	 * Derived classes must implement this method to perform custom validation
	 * using the check(...) calls
	 */
	public function validateContent() {

		if ( !$this->thorough() ) {
			// We are not doing any modifications to the original, so no need to validate it
			return;
		}

		$this->test( 'license', JCValidators::isString(), self::isValidLicense() );
		$this->testOptional( 'info', [ 'en' => '' ], JCValidators::isLocalizedString() );
	}

	/** Returns a validator function to check if the value is a valid string
	 * @return callable
	 */
	public static function isValidLicense() {
		return function ( JCValue $v, array $path ) {
			global $wgJsonConfigAllowedLicenses, $wgLang;
			if ( !in_array( $v->getValue(), $wgJsonConfigAllowedLicenses, true ) ) {
				$v->error( 'jsonconfig-err-license', $path,
					$wgLang->commaList( $wgJsonConfigAllowedLicenses ) );
				return false;
			}
			return true;
		};
	}

	/**
	 * Get data as localized for the given language
	 * @param Language $lang
	 * @return mixed
	 */
	public function getLocalizedData( Language $lang ) {
		if ( !$this->isValid() ) {
			return null;
		}
		$result = new stdClass();
		$this->localizeData( $result, $lang );
		return $result;
	}

	/**
	 * Resolve any override-specific localizations, and add it to $result
	 * @param object $result
	 * @param Language $lang
	 */
	protected function localizeData( $result, Language $lang ) {
		$data = $this->getData();
		if ( property_exists( $data, 'info' ) ) {
			$result->info = JCUtils::pickLocalizedString( $data->info, $lang );
		}
		if ( property_exists( $data, 'license' ) ) {
			$result->license = (object)[
				'code' => $data->license,
				'text' => wfMessage( 'jsonconfig-license-' . $data->license )->plain(),
				'url' => wfMessage( 'jsonconfig-license-url-' . $data->license )->plain(),
			];
		}
	}

	public function renderInfo( $lang ) {
		$info = $this->getField( 'info' );

		if ( $info && !$info->error() ) {
			$info = JCUtils::pickLocalizedString( $info->getValue(), $lang );
			$html = Html::element( 'p', [ 'class' => 'mw-jsonconfig-info' ], $info );
		} else {
			$html = '';
		}

		return $html;
	}

	public function renderLicense() {
		$license = $this->getField( 'license' );

		if ( $license && !$license->error() ) {

			$text = Html::element( 'a', [
				'href' => wfMessage( 'jsonconfig-license-url-' . $license->getValue() )->plain()
			], wfMessage( 'jsonconfig-license-' . $license->getValue() )->plain() );

			$html = Html::rawElement( 'p', [ 'class' => 'mw-jsonconfig-license' ], $text );
		} else {
			$html = '';
		}

		return $html;
	}
}
