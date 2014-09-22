<?php

namespace JsonConfig;

use FormatJson;
use TextContentHandler;

/**
 * JSON Json Config content handler
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 *
 * @author Yuri Astrakhan <yurik@wikimedia.org>
 */
class JCContentHandler extends TextContentHandler {
	/**
	 * @param string $modelId
	 */
	public function __construct( $modelId ) {
		parent::__construct( $modelId, array( CONTENT_FORMAT_JSON ) );
	}

	/**
	 * Returns the content's text as-is.
	 *
	 * @param \Content|\JsonConfig\JCContent $content This is actually a Content object
	 * @param $format string|null
	 * @return mixed
	 */
	public function serializeContent( \Content $content, $format = null ) {
		$this->checkFormat( $format );
		$status = $content->getStatus();
		if ( $status->isGood() ) {
			$data = $content->getData(); // There are no errors, normalize data
		} elseif ( $status->isOK() ) {
			$data = $content->getRawData(); // JSON is valid, but the data has errors
		} else {
			return $content->getNativeData(); // Invalid JSON - can't do anything with it
		}

		return FormatJson::encode( $data, true, FormatJson::ALL_OK );
	}

	/**
	 * Unserializes a JsonSchemaContent object.
	 *
	 * @param string $text Serialized form of the content
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
}
