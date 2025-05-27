<?php

namespace JsonConfig;

use MediaWiki\Status\Status;

class JCContentLoader {
	/** @var JCTransformer */
	private $transformer;
	/** @var JCApiUtils */
	private $utils;
	/** @var ?JCTitle */
	private $title;
	/** @var ?JCTransform */
	private $transform;

	public function __construct( JCTransformer $transformer, JCApiUtils $utils ) {
		$this->transformer = $transformer;
		$this->utils = $utils;

		$this->title = null;
		$this->transform = null;
	}

	// Modifiers

	/**
	 * Set the title
	 */
	public function title( JCTitle $title ): self {
		$this->title = $title;
		return $this;
	}

	/**
	 * Append a transform specification to this content loader pipeline.
	 */
	public function transform( JCTransform $transform ): self {
		$this->transform = $transform;
		return $this;
	}

	// Accessors

	/**
	 * Get the JCTitle/TitleValue of the target page.
	 */
	public function getTitle(): ?JCTitle {
		return $this->title;
	}

	/**
	 * Get the optional transform spec
	 */
	public function getTransform(): ?JCTransform {
		return $this->transform;
	}

	// Actions

	/**
	 * @return Status<JCContentWrapper>
	 */
	public function load(): Status {
		if ( $this->transform && $this->title->getConfig()->store ) {
			return $this->localTransform();
		} elseif ( $this->transform ) {
			return $this->remoteTransform();
		} else {
			return $this->loadSimple();
		}
	}

	// Internals

	/**
	 * Fetch non-transformed data from cache or fresh.
	 * This uses the JCCache-mediated code paths, and may fetch from
	 * a remote resource.
	 * @return Status<JCContent>
	 */
	private function getSimple(): Status {
		if ( !$this->title ) {
			return Status::newFatal( 'jsonconfig-invalid-state' );
		}
		$cache = new JCCache( $this->title );
		return JCUtils::hydrate( $this->title, $cache->get() );
	}

	/**
	 * Simple load up for when we have no transforms.
	 */
	private function loadSimple(): Status {
		$status = $this->getSimple();
		if ( $status->isOk() ) {
			if ( !$this->title ) {
				return Status::newFatal( 'jsonconfig-invalid-state' );
			}
			$wrapper = JCContentWrapper::newFromContent( $this->title, $status->getValue() );
			$status = Status::newGood( $wrapper );
		}
		return $status;
	}

	/**
	 * Load data and run transform locally; we are the store wiki.
	 * @return Status<JCContentWrapper>
	 */
	private function localTransform(): Status {
		$status = $this->loadSimple();
		if ( $status->isOk() ) {
			if ( !$this->title ) {
				return Status::newFatal( 'jsonconfig-invalid-state' );
			}
			$wrapper = $status->getValue();
			$content = $wrapper->getContent();
			$status = $this->transformer->execute( $this->title, $content, $this->transform );
		}
		return $status;
	}

	/**
	 * Request transformed data from remote site via API.
	 * @return Status<JCContentWrapper>
	 */
	private function remoteTransform(): Status {
		$conf = $this->title->getConfig();
		$remote = $conf->remote ?? [];
		$req = $this->utils->initApiRequestObj(
			$remote->url ?? null,
			$remote->username ?? null,
			$remote->password ?? null
		);
		if ( !$req ) {
			return Status::newFatal( 'jsontransform-remote-invalid-config' );
		}

		$query = [
			'action' => 'jsontransform',
			'title' => $this->title->getDbKey(),
			'jtmodule' => $this->transform->getModule(),
			'jtfunction' => $this->transform->getFunction(),
			'jtargs' => $this->transformArgsForApi(),
		];

		$status = $this->utils->callApiStatus( $req, $query, 'get remote jsontransform result' );
		if ( $status->isGood() ) {
			$result = $status->getValue();
			if ( !isset( $result['jsontransform'] ) ) {
				$status = Status::newFatal( 'jsontransform-invalid-result' );
			}
		}
		if ( $status->isGood() ) {
			if ( !$this->title ) {
				return Status::newFatal( 'jsonconfig-invalid-state' );
			}
			// Annoying hack for obj vs array formats
			$result = json_decode( json_encode( $result['jsontransform'] ?? [] ) );
			$status = JCContentWrapper::newFromJson( $this->title, $result );
		}
		return $status;
	}

	private function transformArgsForApi(): string {
		$args = '';
		foreach ( $this->transform->getArgs() as $key => $value ) {
			// Arguments could contain "|" pipe character so use the alternate
			// escaping mechanism for the API multi-value fields.
			$args .= "\x1f$key=$value";
		}
		return $args;
	}
}
