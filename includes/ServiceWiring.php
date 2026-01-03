<?php

use JsonConfig\GlobalJsonLinks;
use JsonConfig\JCApiUtils;
use JsonConfig\JCContentLoaderFactory;
use JsonConfig\JCTransformer;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

/** @phpcs-require-sorted-array */
return [
	'JsonConfig.ApiUtils' => static function ( MediaWikiServices $services ): JCApiUtils {
		return new JCApiUtils(
			$services->getHttpRequestFactory()
		);
	},
	'JsonConfig.ContentLoaderFactory' => static function ( MediaWikiServices $services ): JCContentLoaderFactory {
		return new JCContentLoaderFactory(
			$services->getService( 'JsonConfig.Transformer' ),
			$services->getService( 'JsonConfig.ApiUtils' )
		);
	},
	'JsonConfig.GlobalJsonLinks' => static function ( MediaWikiServices $services ): GlobalJsonLinks {
		return new GlobalJsonLinks(
			$services->getMainConfig(),
			$services->getConnectionProvider(),
			$services->getNamespaceInfo(),
			$services->getTitleFormatter(),
			WikiMap::getCurrentWikiId()
		);
	},
	'JsonConfig.Transformer' => static function ( MediaWikiServices $services ): JCTransformer {
		return new JCTransformer(
			$services->getMainConfig(),
			$services->getParserFactory(),
			$services->hasService( 'Scribunto.EngineFactory' ) ?
				$services->getService( 'Scribunto.EngineFactory' ) :
				null
		);
	},
];
