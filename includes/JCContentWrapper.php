<?php

namespace JsonConfig;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Status\Status;
use MediaWiki\Title\TitleValue;
use stdClass;

class JCContentWrapper {
	/**
	 * @var JCContent JSON data payload
	 */
	protected $content;

	/**
	 * Timestamp of the load/transform operation
	 * @var string MediaWiki timestamp
	 */
	protected $timestamp;

	/**
	 * @var int recommended cache TTL in seconds
	 */
	protected $expiry;

	/**
	 * @var TitleValue[] list of pages on the store wiki that should trigger reparsing when they change
	 */
	protected $dependencies;

	/**
	 * @param JCContent $content JSON-style data object
	 * @param int $expiry max time to live for cached output in seconds
	 * @param TitleValue[] $dependencies pages which when edited should invalidate this cache entry
	 */
	public function __construct( JCContent $content, int $expiry, array $dependencies ) {
		$this->content = $content;
		$this->expiry = $expiry;
		$this->dependencies = $dependencies;
	}

	/**
	 * Return the encapsulated JCContent data object.
	 * @return JCContent
	 */
	public function getContent(): JCContent {
		return $this->content;
	}

	/**
	 * Return the max cache expiry in seconds.
	 * @return int
	 */
	public function getExpiry(): int {
		return $this->expiry;
	}

	/**
	 * Return the dependencies list, should include any pages loaded by the transform script.
	 * @return TitleValue[]
	 */
	public function getDependencies(): array {
		return $this->dependencies;
	}

	/**
	 * Combine transformed JSON output with optional metadata from ParserOutput
	 * @param JCTitle $title
	 * @param JCContent $content
	 * @param ?ParserOutput $output metadata source for expiry and deps
	 * @return self
	 */
	public static function newFromContent( JCTitle $title, JCContent $content, ?ParserOutput $output = null ): self {
		if ( $output ) {
			$expiry = $output->getCacheExpiry();

			// Resources loaded via Scribunto Lua module will appear in template links
			$templates = array_map( static function ( $link ) {
				return $link['link'];
			}, $output->getLinkList( ParserOutputLinkTypes::TEMPLATE ) );

			// Data dependencies referenced via mw.ext.data.get will appear in jsonlinks
			$jsonlinks = JCSingleton::getJsonLinks( $output );

			$dependencies = array_unique( array_merge( $templates, $jsonlinks ) );
		} else {
			$expiry = MediaWikiServices::getInstance()->getMainConfig()->get(
				MainConfigNames::ParserCacheExpireTime );
			$dependencies = [ $title ];
		}

		return new self( $content, $expiry, $dependencies );
	}

	/**
	 * Create a JCContentWrapper from a remote-supplied JSON object.
	 * @param JCTitle $title
	 * @param stdClass $o JSON wrapper
	 * @return Status<JCContentWrapper>
	 */
	public static function newFromJSON( JCTitle $title, stdClass $o ): Status {
		$data = (object)$o->data;
		$expiry = intval( $o->expiry ?? 0 );
		$dependencies = array_map( static function ( $obj ) {
			$obj = (object)$obj;
			return new TitleValue(
				intval( $obj->namespace ?? 0 ),
				strval( $obj->title ?? '' )
			);
		}, $o->dependencies ?? [] );

		$status = JCUtils::hydrate( $title, $data );
		if ( $status->isOk() ) {
			$status = Status::newGood( new self( $status->getValue(), $expiry, $dependencies ) );
		}
		return $status;
	}

	/**
	 * Export the filter result as a JSON-friendly object.
	 * @return stdClass
	 */
	public function toJSON(): stdClass {
		return (object)[
			"data" => $this->getContent()->getData(),
			"expiry" => $this->getExpiry(),
			"dependencies" => array_map( static function ( $link ) {
				return (object)[
					'namespace' => $link->getNamespace(),
					'title' => $link->getDbKey(),
				];
			}, $this->getDependencies() )
		];
	}

	/**
	 * Apply expiration and dependency metadata to a ParserOutput object.
	 */
	public function addToParserOutput( ParserOutput $output ) {
		$expiry = $this->getExpiry();
		$output->updateCacheExpiry( $expiry );

		foreach ( $this->getDependencies() as $title ) {
			JCSingleton::recordJsonLink( $output, $title );
		}
	}
}
