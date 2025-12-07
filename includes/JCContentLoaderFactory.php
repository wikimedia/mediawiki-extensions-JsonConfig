<?php

namespace JsonConfig;

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
