<?php

namespace JsonConfig;

use Html;

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
