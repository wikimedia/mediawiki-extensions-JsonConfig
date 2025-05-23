<?php

namespace JsonConfig;

use MediaWiki\Content\CodeContentHandler;
use MediaWiki\Content\Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\Transform\PreSaveTransformParams;
use MediaWiki\Context\IContextSource;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;

/**
 * JSON Json Config content handler
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 *
 * @author Yuri Astrakhan <yurik@wikimedia.org>
 */
class JCContentHandler extends CodeContentHandler {

	/**
	 * Internal format to force pretty-printed json serialization
	 */
	public const CONTENT_FORMAT_JSON_PRETTY = 'application/json+pretty';

	/**
	 * @param string $modelId
	 */
	public function __construct( $modelId ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_JSON, self::CONTENT_FORMAT_JSON_PRETTY ] );
	}

	/**
	 * Returns the content's text as-is.
	 *
	 * @param Content|JCContent $content This is actually a Content object
	 * @param string|null $format
	 * @return mixed
	 */
	public function serializeContent( Content $content, $format = null ) {
		$this->checkFormat( $format );
		$status = $content->getStatus();
		if ( $status->isGood() ) {
			$data = $content->getData(); // There are no errors, normalize data
		} elseif ( $status->isOK() ) {
			$data = $content->getRawData(); // JSON is valid, but the data has errors
		} else {
			return $content->getNativeData(); // Invalid JSON - can't do anything with it
		}

		return FormatJson::encode( $data, $format === self::CONTENT_FORMAT_JSON_PRETTY,
			FormatJson::ALL_OK );
	}

	/**
	 * @param Content|JCContent $oldContent
	 * @param Content|JCContent $myContent
	 * @param Content|JCContent $yourContent
	 * @return bool|JCContent
	 */
	public function merge3( Content $oldContent, Content $myContent, Content $yourContent ) {
		// Almost identical clone of the parent's merge3, except that we use pretty-printed merge,
		// thus allowing much more lenient line-based merging.

		$this->checkModelID( $oldContent->getModel() );
		$this->checkModelID( $myContent->getModel() );
		$this->checkModelID( $yourContent->getModel() );

		$format = self::CONTENT_FORMAT_JSON_PRETTY;

		$old = $this->serializeContent( $oldContent, $format );
		$mine = $this->serializeContent( $myContent, $format );
		$yours = $this->serializeContent( $yourContent, $format );

		$ok = wfMerge( $old, $mine, $yours, $result );

		if ( !$ok ) {
			return false;
		}

		if ( !$result ) {
			return $this->makeEmptyContent();
		}

		return $this->unserializeContent( $result, $format );
	}

	/** @inheritDoc */
	protected function getSlotDiffRendererWithOptions( IContextSource $context, $options = [] ) {
		return new JCSlotDiffRenderer( $this->createTextSlotDiffRenderer( $options ) );
	}

	/**
	 * Unserializes a JsonSchemaContent object.
	 *
	 * @param string|null $text Serialized form of the content
	 * @param null|string $format The format used for serialization
	 * @param bool $isSaving Perform extra validation
	 * @return JCContent the JsonSchemaContent object wrapping $text
	 */
	public function unserializeContent( $text, $format = null, $isSaving = true ) {
		$this->checkFormat( $format );
		$modelId = $this->getModelID();
		$class = JCSingleton::getContentClass( $modelId );
		return new $class( $text, $modelId, $isSaving );
	}

	/** @inheritDoc */
	protected function getContentClass() {
		return JCSingleton::getContentClass( $this->getModelID() );
	}

	/**
	 * Creates an empty JsonSchemaContent object.
	 *
	 * @return JCContent
	 */
	public function makeEmptyContent() {
		// Each model could have its own default JSON value
		// null notifies that default should be used
		return $this->unserializeContent( null );
	}

	/**
	 * @inheritDoc
	 */
	public function preSaveTransform(
		Content $content,
		PreSaveTransformParams $pstParams
	): Content {
		'@phan-var JCContent $content';

		$contentClass = $this->getContentClass();
		if ( !$content->isValidJson() ) {
			return $content; // Invalid JSON - can't do anything with it
		}
		$formatted = FormatJson::encode( $content->getData(), false, FormatJson::ALL_OK );
		if ( $content->getText() !== $formatted ) {
			return new $contentClass( $formatted, $content->getModel(), $content->thorough() );
		}
		return $content;
	}

	/**
	 * @inheritDoc
	 */
	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	) {
		'@phan-var JCContent $content';
		$page = $cpoParams->getPage();
		$generateHtml = $cpoParams->getGenerateHtml();
		$revId = $cpoParams->getRevId();
		$parserOptions = $cpoParams->getParserOptions();
		if ( !$generateHtml ) {
			return;
		}

		$status = $content->getStatus();
		if ( !$status->isGood() ) {
			// Use user's language, and split parser cache.  This should not have a big
			// impact because data namespace is rarely viewed, but viewing it localized
			// will be valuable
			$lang = $parserOptions->getUserLangObj();
			$html = $status->getHTML( false, false, $lang );
		} else {
			$html = '';
		}

		if ( $status->isOK() ) {
			$html .= $content
				->getView( $content->getModel() )
				->valueToHtml( $content, $page, $revId, $parserOptions, $generateHtml, $output );

			if ( $content instanceof JCDataContent && $content->isValid() ) {
				$this->addCategoriesToParserOutput( $content, $output );
			}
		}

		$output->setText( $html );
	}

	/**
	 * @param JCDataContent $content
	 * @param ParserOutput $parserOutput
	 */
	protected function addCategoriesToParserOutput( JCDataContent $content, ParserOutput $parserOutput ) {
		$titleParser = MediaWikiServices::getInstance()->getTitleParser();

		$categoriesField = $content->getField( 'mediawikiCategories' );
		if ( $categoriesField && !$categoriesField->error() ) {
			$categories = $categoriesField->getValue();
			foreach ( $categories as $categoryItem ) {
				$categoryName = $categoryItem->name;
				$sortKey = $categoryItem->sort ?? '';

				$categoryTitle = $titleParser->makeTitleValueSafe( NS_CATEGORY, $categoryName );
				if ( $categoryTitle !== null ) {
					$parserOutput->addCategory( $categoryTitle->getDBkey(), $sortKey );
				}
			}
		}
	}
}
