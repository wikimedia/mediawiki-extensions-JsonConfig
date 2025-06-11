<?php

namespace JsonConfig;

class JCContentLoaderFactory {
	/** @var JCTransformer */
	private $transformer;
	/** @var JCApiUtils */
	private $utils;

	public function __construct( JCTransformer $transformer, JCApiUtils $utils ) {
		$this->transformer = $transformer;
		$this->utils = $utils;
	}

	public function get( JCTitle $title ): JCContentLoader {
		return ( new JCContentLoader( $this->transformer, $this->utils ) )
			->title( $title );
	}
}
