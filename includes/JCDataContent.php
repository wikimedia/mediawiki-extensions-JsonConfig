<?php

namespace JsonConfig;

use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\StubObject\StubUserLang;
use stdClass;

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

		$this->test( 'license', JCValidators::isStringLine(), self::isValidLicense() );
		$this->testOptional( 'description', [ 'en' => '' ], JCValidators::isLocalizedString() );
		$this->testOptional( 'sources', '', JCValidators::isString() );
		$this->testOptional( 'mediawikiCategories', [], self::isValidCategories() );
	}

	/** Returns a validator function to check if the value is a valid string
	 * @return callable
	 */
	public static function isValidLicense() {
		return static function ( JCValue $v, array $path ) {
			global $wgLang;
			$allowedLicenses = MediaWikiServices::getInstance()->getMainConfig()->get( 'JsonConfigAllowedLicenses' );
			if ( !in_array( $v->getValue(), $allowedLicenses, true ) ) {
				$v->error( 'jsonconfig-err-license', $path,
					$wgLang->commaList( $allowedLicenses ) );
				return false;
			}
			return true;
		};
	}

	/**
	 * Returns a function to check that categories is a valid array,
	 * with name and sort (optional) properties.
	 * @return callable
	 */
	public static function isValidCategories() {
		return static function ( JCValue $v, array $path ) {
			$categories = $v->getValue();
			if ( !is_array( $categories ) ) {
				$v->error( 'jsonconfig-err-array', $path );
				return false;
			}

			foreach ( $categories as $idx => $category ) {
				$itemPath = array_merge( $path, [ $idx ] );

				if ( !is_object( $category ) ) {
					$v->error( 'jsonconfig-err-category-invalid', $itemPath );
					return false;
				}

				if ( !property_exists( $category, 'name' )
					|| !is_string( $category->name ) ) {
					$v->error( 'jsonconfig-err-category-name-invalid', $itemPath );
					return false;
				}

				if ( property_exists( $category, 'sort' )
					&& !is_string( $category->sort ) ) {
					$v->error( 'jsonconfig-err-category-sort-invalid', $itemPath );
					return false;
				}
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
		$result = (object)[];
		$this->localizeData( $result, $lang );
		return $result;
	}

	/**
	 * Resolve any override-specific localizations, and add it to $result
	 * @param stdClass $result
	 * @param Language $lang
	 */
	protected function localizeData( $result, Language $lang ) {
		$data = $this->getData();
		if ( property_exists( $data, 'description' ) ) {
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$result->description = JCUtils::pickLocalizedString( $data->description, $lang );
		}
		$license = $this->getLicenseObject();
		if ( $license ) {
			$text = $license['text']->inLanguage( $lang )->plain();
			$result->license = (object)[
				'code' => $license['code'],
				'text' => $text,
				'url' => $license['url']->inLanguage( $lang )->plain(),
			];
		}
		if ( property_exists( $data, 'sources' ) ) {
			$result->sources = $data->sources;
		}
		if ( property_exists( $data, 'mediawikiCategories' ) ) {
			$result->mediawikiCategories = $data->mediawikiCategories;
		}
	}

	/**
	 * @param Language|StubUserLang $lang
	 * @return string
	 */
	public function renderDescription( $lang ) {
		$description = $this->getField( 'description' );
		if ( !$description || $description->error() ) {
			return '';
		}

		$description = JCUtils::pickLocalizedString( $description->getValue(), $lang );
		return Html::element( 'p', [ 'class' => 'mw-jsonconfig-description' ], $description );
	}

	/**
	 * Renders license HTML, including optional "or later version" clause
	 *     <a href="...">Creative Commons 1.0</a>, or later version
	 * @return string
	 */
	public function renderLicense() {
		$license = $this->getLicenseObject();
		if ( !$license ) {
			return '';
		}

		$text = Html::element( 'a', [
			'href' => $license['url']->plain()
		], $license['text']->plain() );
		$text = wfMessage( 'jsonconfig-license' )->rawParams( $text )->parse();
		return Html::rawElement( 'p', [ 'class' => 'mw-jsonconfig-license' ], $text );
	}

	/**
	 * Get the license object of the content.
	 * license code is identifier from https://spdx.org/licenses/
	 * @return array|false code=>license code text=>license name url=>license URL
	 */
	public function getLicenseObject() {
		$license = $this->getField( 'license' );
		if ( !$license || $license->error() ) {
			return false;
		}

		// should be a valid license identifier as in https://spdx.org/licenses/
		$code = $license->getValue();
		return [
			'code' => $code,
			'text' => wfMessage( 'jsonconfig-license-name-' . $code ),
			'url' => wfMessage( 'jsonconfig-license-url-' . $code ),
		];
	}

	/**
	 * @param Parser $parser
	 * @param PageReference $page
	 * @param int|null $revId
	 * @param ParserOptions $options
	 * @return string
	 */
	public function renderSources( Parser $parser, PageReference $page, $revId, ParserOptions $options ) {
		$sources = $this->getField( 'sources' );
		if ( !$sources || $sources->error() ) {
			return '';
		}

		$markup = $sources->getValue();
		return Html::rawElement( 'p', [ 'class' => 'mw-jsonconfig-sources' ],
			$parser->parse( $markup, $page, $options, true, true, $revId )->getRawText() );
	}

}
