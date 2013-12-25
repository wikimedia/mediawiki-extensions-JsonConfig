<?php

namespace JsonConfig;

use FormatJson;
use Title;
use Html;
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
	/** @var array */
	private $data = null;
	/** @var \Status */
	private $status;
	/** @var bool */
	private $isSaving;

	/**
	 * @param string $text Json configuration. If null, default content will be inserted instead
	 * @param string $modelId
	 * @param bool $isSaving True if extra validation should be performed
	 */
	public function __construct( $text, $modelId, $isSaving ) {
		if ( $text === null ) {
			$text = "{\n}";
		}
		parent::__construct( $text, $modelId );
		$this->isSaving = $isSaving;
		$this->status = new Status();
		$this->parse();
	}

	/**
	 * Get configuration data
	 * @return array
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

	/**
	 * Returns true if the text is in JSON format.
	 * @return bool
	 */
	public function isValidJson() {
		return $this->rawData !== null;
	}

	/**
	 * @return boolean
	 */
	public function isSaving() {
		return $this->isSaving;
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
		$data = FormatJson::decode( $rawText, true );
		if ( $data === null ) {
			if ( $this->isSaving ) {
				// The most common error is the trailing comma in a list. Attempt to remove it.
				// We have to do it only once, as otherwise there could be an edge case like
				// ',\n}' being part of a multi-line string value, in which case we should fail
				$count = 0;
				$rawText = preg_replace( '/,[ \t]*([\r\n]*[ \t]*[\]}])/', '$1', $rawText, 1, $count );
				if ( $count > 0 ) {
					$data = FormatJson::decode( $rawText, true );
				}
			}
			if ( $data === null ) {
				$this->status->fatal( 'jsonconfig-bad-json' );

				return;
			}
		}
		$this->rawData = $data;
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
			$class = get_class( $this );

			return new $class( $formatted, $this->getModel(), $this->isSaving() );
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
			$html .= $this->valueToHtml( $this->getData() );
		}
		wfProfileOut( __METHOD__ );

		return $html;
	}

	/**
	 * Convert array's key-value pair into a string of <th>...</th><td>...</td> elements
	 * @param int|string $key
	 * @param mixed $value
	 * @param int $level
	 * @param int|string $parentKey
	 * @param mixed $parentValue
	 * @return string
	 */
	public function rowToHtml( $key, $value, $level, $parentKey, $parentValue ) {
		if ( is_string( $key ) ) {
			$th = Html::element( 'th', null, $key );
		} else {
			$th = '';
		}

		$tdVal = $this->valueToHtml( $value, $key, $level );

		// If html begins with a '<', its a complex object, and should not have a class
		$attribs = null;
		if ( substr( $tdVal, 0, 1 ) !== '<' ) {
			$attribs = array( 'class' => 'mw-jsonconfig-value' );
		}
		$td = Html::rawElement( 'td', $attribs, $tdVal );

		return $th . $td;
	}

	/**
	 * Constructs an HTML representation of a JSON object.
	 * @param mixed $value
	 * @param int|string|null $key
	 * @param int $level Tree level
	 * @return string: HTML.
	 */
	public function valueToHtml( $value, $key = null, $level = 0 ) {
		if ( is_array( $value ) && count( $value ) !== 0 ) {
			$rows = array();
			foreach ( $value as $k => $v ) {
				$rows[] = Html::rawElement( 'tr', null,
					$this->rowToHtml( $k, $v, $level + 1, $key, $value ) );
			}
			$res = Html::rawElement( 'table', array( 'class' => 'mw-jsonconfig' ),
				Html::rawElement( 'tbody', null, join( "\n", $rows ) ) );
		} elseif ( is_array( $value ) ) {
			$res = '';
		} elseif ( !is_string( $value ) ) {
			$res = FormatJson::encode( $value );
		} else {
			$res = $value;
		}

		return $res;
	}
}
