<?php

namespace JsonConfig;

use FormatJson;
use Title;
use Status;

/**
 * Represents the content of a JSON Json Config article.
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 *
 * @author Yuri Astrakhan <yurik@wikimedia.org>, based on Ori Livneh <ori@wikimedia.org> extension schema
 */
class JCContent extends \TextContent {
	/** @var array */
	private $rawData = null;
	/** @var \stdClass|array */
	protected $data = null;
	/** @var \Status */
	private $status;
	/** @var bool */
	private $thorough;
	/** If false, JSON parsing will use stdClass instead of array for "{...}" */
	protected $useAssocParsing = false;
	/** @var JCContentView|null contains an instance of the view class */
	private $view = null;

	/**
	 * @param string $text Json configuration. If null, default content will be inserted instead
	 * @param string $modelId
	 * @param bool $thorough True if extra validation should be performed
	 */
	public function __construct( $text, $modelId, $thorough ) {
		if ( $text === null ) {
			$text = $this->getView( $modelId )->getDefault( $modelId );
		}
		parent::__construct( $text, $modelId );
		$this->thorough = $thorough;
		$this->status = new Status();
		$this->parse();
	}

	/**
	 * Get validated data
	 * @return array|\stdClass
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Returns JSON object as resulted from parsing initial text, before any validation/modifications took place
	 * @return mixed
	 */
	public function getRawData() {
		return $this->rawData;
	}

	/**
	 * Get content status object
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * @return bool: False if this configuration has parsing or validation errors
	 */
	public function isValid() {
		return $this->status->isGood();
	}

	public function isEmpty() {
		$text = trim( $this->getNativeData() );
		return $text === '' || $text === '{}';
	}

	/*
	 * Determines whether this content should be considered a "page" for statistics
	 * In our case, just making sure it's not empty or a redirect
	 *
	 */
	public function isCountable( $hasLinks = null ) {
		return !$this->isEmpty() && !$this->isRedirect();
	}

	/**
	 * Returns true if the text is in JSON format.
	 * @return bool
	 */
	public function isValidJson() {
		return $this->rawData !== null;
	}

	/**
	 * @return boolean true if thorough validation may be needed - e.g. rendering HTML or saving new value
	 */
	public function thorough() {
		return $this->thorough;
	}

	/**
	 * Override this method to perform additional data validation
	 */
	public function validate( $data ) {
		return $data;
	}

	/**
	 * Perform initial json parsing and validation
	 */
	private function parse() {
		$rawText = $this->getNativeData();
		$data = FormatJson::decode( $rawText, $this->useAssocParsing );
		if ( $data === null ) {
			if ( $this->thorough ) {
				// The most common error is the trailing comma in a list. Attempt to remove it.
				// We have to do it only once, as otherwise there could be an edge case like
				// ',\n}' being part of a multi-line string value, in which case we should fail
				$count = 0;
				$rawText = preg_replace( '/,[ \t]*([\r\n]*[ \t]*[\]}])/', '$1', $rawText, 1, $count );
				if ( $count > 0 ) {
					$data = FormatJson::decode( $rawText, $this->useAssocParsing );
				}
			}
			if ( $data === null ) {
				$this->status->fatal( 'jsonconfig-bad-json' );
				return;
			}
		}
		if ( !$this->useAssocParsing ) {
			// @fixme: HACK - need a deep clone of the data
			// @fixme: but doing (object)(array)$data will re-encode empty [] as {}
			$this->rawData = FormatJson::decode( $rawText, $this->useAssocParsing );
		} else {
			$this->rawData = $data;
		}
		$this->data = $this->validate( $data );
	}

	/**
	 * Beautifies JSON prior to save.
	 * @param Title $title Title
	 * @param \User $user User
	 * @param \ParserOptions $popts
	 * @return JCContent
	 */
	public function preSaveTransform( Title $title, \User $user, \ParserOptions $popts ) {
		if ( !$this->isValidJson() ) {
			return $this; // Invalid JSON - can't do anything with it
		}
		$formatted = FormatJson::encode( $this->getData(), true, FormatJson::ALL_OK );
		if ( $this->getNativeData() !== $formatted ) {
			return new static( $formatted, $this->getModel(), $this->thorough() );
		}
		return $this;
	}

	/**
	 * Generates HTML representation of the content.
	 * @return string HTML representation.
	 */
	public function getHtml() {
		wfProfileIn( __METHOD__ );
		$status = $this->getStatus();
		if ( $status->isGood() ) {
			$html = '';
		} else {
			$html = $status->getHTML();
		}
		if ( $status->isOK() ) {
			$html .= $this->getView( $this->getModel() )->valueToHtml( $this );
		}
		wfProfileOut( __METHOD__ );

		return $html;
	}

	/**
	 * Get a view object for this content object
	 * @param string $modelId is required here because parent ctor might not have ran yet
	 * @return JCContentView
	 */
	protected function getView( $modelId ) {
		$view = $this->view;
		if ( $view === null ) {
			global $wgJsonConfigModels;
			if ( array_key_exists( $modelId, $wgJsonConfigModels ) ) {
				$value = $wgJsonConfigModels[$modelId];
				if ( is_array( $value ) && array_key_exists( 'view', $value ) ) {
					$class = $value['view'];
					$view = new $class();
				}
			}
			if ( $view === null ) {
				$view = $this->createDefaultView();
			}
			$this->view = $view;
		}
		return $view;
	}

	/**
	 * In case view is not associated with the model for this class, this function will instantiate a default.
	 * Override may instantiate a more appropriate view
	 * @return JCContentView
	 */
	protected function createDefaultView() {
		return new JCDefaultContentView();
	}
}
