<?php

namespace MediaWiki\Extension\JsonConfig;

class JCContentLoaderFactory {
	public function __construct(
		private readonly JCTransformer $transformer,
		private readonly JCApiUtils $utils,
	) {
	}

	public function get( JCTitle $title ): JCContentLoader {
		return ( new JCContentLoader( $this->transformer, $this->utils ) )
			->title( $title );
	}
}

/** @deprecated Temporary backwards-compatible class alias */
class_alias( JCContentLoaderFactory::class, 'JsonConfig\\JCContentLoaderFactory' );
