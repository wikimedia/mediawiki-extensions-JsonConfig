<?php

namespace JsonConfig;

use MediaWiki\Content\TextContent;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Status\Status;
use stdClass;

/**
 * Represents the content of a JSON Json Config article.
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 *
 * @author Yuri Astrakhan <yurik@wikimedia.org>,
 *   based on Ori Livneh <ori@wikimedia.org> extension schema
 */
class JCContent extends TextContent {
	/** @var mixed */
	private $rawData = null;
	/** @var stdClass|null */
	protected $data = null;
	private Status $status;
	private bool $thorough;
	private bool $stripComments;
	/** @var JCContentView|null contains an instance of the view class */
	private ?JCContentView $view = null;

	/**
	 * @param string|null $text Json configuration. If null, default content will be inserted instead
	 * @param string $modelId
	 * @param bool $thorough True if extra validation should be performed
	 */
	public function __construct( ?string $text, string $modelId, bool $thorough ) {
		$this->stripComments = $text !== null;
		$text ??= $this->getView( $modelId )->getDefault( $modelId );
		parent::__construct( $text, $modelId );
		$this->thorough = $thorough;
		$this->status = new Status();
		$this->parse();
	}

	/**
	 * Get validated data
	 * @return stdClass
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Returns data after sanitization, suitable for third-party use
	 *
	 * @param stdClass $data
	 * @return stdClass
	 */
	public function getSafeData( $data ) {
		return $data;
	}

	/**
	 * Returns JSON object as resulted from parsing initial text,
	 * before any validation/modifications took place
	 * @return mixed
	 */
	public function getRawData() {
		return $this->rawData;
	}

	/**
	 * Get content status object
	 * @return Status
	 */
	public function getStatus(): Status {
		return $this->status;
	}

	/**
	 * @return bool False if this configuration has parsing or validation errors
	 */
	public function isValid(): bool {
		return $this->status->isGood();
	}

	/** @inheritDoc */
	public function isEmpty(): bool {
		$text = trim( $this->getNativeData() );
		return $text === '' || $text === '{}';
	}

	/**
	 * Determines whether this content should be considered a "page" for statistics
	 * In our case, just making sure it's not empty or a redirect
	 * @param bool|null $hasLinks
	 * @return bool
	 */
	public function isCountable( $hasLinks = null ): bool {
		return !$this->isEmpty() && !$this->isRedirect();
	}

	/**
	 * Returns true if the text is in JSON format.
	 * @return bool
	 */
	public function isValidJson(): bool {
		return $this->rawData !== null;
	}

	/**
	 * @return bool true if thorough validation may be needed -
	 *   e.g. rendering HTML or saving new value
	 */
	public function thorough(): bool {
		return $this->thorough;
	}

	/**
	 * Override this method to perform additional data validation
	 * @param mixed $data
	 * @return stdClass
	 */
	public function validate( $data ) {
		return $data;
	}

	/**
	 * Perform initial json parsing and validation
	 */
	private function parse(): void {
		$rawText = $this->getNativeData();
		$parseOpts = FormatJson::TRY_FIXING;
		if ( $this->stripComments ) {
			$parseOpts += FormatJson::STRIP_COMMENTS;
		}
		$status = FormatJson::parse( $rawText, $parseOpts );
		if ( !$status->isOK() ) {
			$this->status = $status;
			return;
		}
		$data = $status->getValue();
		// @fixme: HACK - need a deep clone of the data
		// @fixme: but doing (object)(array)$data will re-encode empty [] as {}
		// @performance: re-encoding is likely faster than stripping comments in PHP twice
		$this->rawData = FormatJson::decode(
			FormatJson::encode( $data, false, FormatJson::ALL_OK ), true
		);
		$this->data = $this->validate( $data );
	}

	/**
	 * Get a view object for this content object
	 * @internal Only public for JCContentHandler
	 *
	 * @param string $modelId is required here because parent ctor might not have ran yet
	 * @return JCContentView
	 */
	public function getView( string $modelId ): JCContentView {
		if ( !$this->view ) {
			$configModels = ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigModels' )
				+ MediaWikiServices::getInstance()->getMainConfig()->get( 'JsonConfigModels' );
			$class = $configModels[$modelId]['view'] ?? null;
			$this->view = $class ? new $class() : $this->createDefaultView();
		}
		return $this->view;
	}

	/**
	 * In case view is not associated with the model for this class, this function will instantiate
	 * a default. Override may instantiate a more appropriate view
	 * @return JCContentView
	 */
	protected function createDefaultView() {
		return new JCDefaultContentView();
	}
}
