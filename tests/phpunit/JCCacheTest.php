<?php

namespace JsonConfig\Tests\Integration;

use HashBagOStuff;
use JsonConfig\JCCache;
use JsonConfig\JCContent;
use JsonConfig\JCTitle;
use MediaWiki\Page\WikiPage;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \JsonConfig\JCCache
 * @group Database
 */
class JCCacheTest extends MediaWikiIntegrationTestCase {

	private function newJCTitle( WikiPage $page ): JCTitle {
		$config = (object)[
			'namespace' => NS_MAIN,
			'flaggedRevs' => null,
			'cacheKey' => 'abc',
			'isLocal' => true,
			'cacheExp' => 100,
			'store' => true,
		];

		return new JCTitle( NS_MAIN, $page->getDBkey(), $config );
	}

	public function testGetWithoutContent(): void {
		$cache = new JCCache(
			$this->newJCTitle( $this->getExistingTestPage( 'Foobar' ) ),
			null
		);

		$result = $cache->get();
		$this->assertSame(
			'Test content for JCCacheTest-testGetWithoutContent',
			$result
		);
	}

	public function testGetWithContent(): void {
		$cache = new JCCache(
			$this->newJCTitle( $this->getExistingTestPage( 'Foobar' ) ),
			new JCContent( '{}', 'test', false )
		);

		$result = $cache->get();

		$this->assertInstanceOf( JCContent::class, $result );
		$this->assertSame( '{}', $result->getText() );
	}

	public function testGetWithInvalidCache(): void {
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->setService( 'MainWANObjectCache', $wanCache );

		$cache = new JCCache(
			$this->newJCTitle( $this->getNonexistingTestPage( 'Foobar' ) ),
			null
		);

		$result = $cache->get();
		$this->assertFalse( $result );
	}

}
