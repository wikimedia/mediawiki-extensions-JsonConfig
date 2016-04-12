<?php

namespace JsonConfig;

use Html;
use ParserOptions;
use ParserOutput;
use Title;

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

		// TODO: handle well-known licenses and link to them
		$this->test( 'license', JCValidators::isString() );
		$this->test( 'info', JCValidators::isLocalizedString() );
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
			$html = Html::element( 'p', [ 'class' => 'mw-jsonconfig-license' ],
				$license->getValue() );
		} else {
			$html = '';
		}

		return $html;
	}
}
