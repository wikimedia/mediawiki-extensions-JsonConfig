<?php

use JsonConfig\GlobalJsonLinks;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

/** @phpcs-require-sorted-array */
return [
	'JsonConfig.GlobalJsonLinks' => static function ( MediaWikiServices $services ): GlobalJsonLinks {
		return new GlobalJsonLinks(
			new ServiceOptions( GlobalJsonLinks::CONFIG_OPTIONS, $services->getMainConfig() ),
			$services->getConnectionProvider(),
			$services->getNamespaceInfo(),
			$services->getTitleFormatter(),
			WikiMap::getCurrentWikiId()
		);
	}
];
