<?php

namespace JsonConfig\Tests\Integration;

use HashBagOStuff;
use JsonConfig\JCCache;
use JsonConfig\JCContent;
use JsonConfig\JCTitle;
use MediaWiki\Page\WikiPage;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \JsonConfig\JCCache
 * @group Database
 */
class JCCacheTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		$this->overrideConfigValue( 'JsonConfigDisableCache', false );
	}

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

	private function newJCCache( bool $withContentOverride = false ): JCCache {
		$content = $withContentOverride ? new JCContent( '{}', 'test', false ) : null;
		return new JCCache(
			$this->newJCTitle( $this->getExistingTestPage( 'Foobar' ) ),
			$content
		);
	}

	public function testGetWithoutContentOverride(): void {
		$cache = $this->newJCCache();

		$result = $cache->get();
		$this->assertSame(
			'Test content for JCCacheTest-newJCCache',
			$result
		);
	}

	public function testGetWithContentOverride(): void {
		$cache = $this->newJCCache( true );
		$result = $cache->get();

		$this->assertInstanceOf( JCContent::class, $result );
		$this->assertSame( '{}', $result->getText() );
	}

	public function testGetWithNonExistingPage(): void {
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->setService( 'MainWANObjectCache', $wanCache );

		// NOTE: We're testing here with a non-existing test page. So we can't
		// use the newJCCache() helper. As that is for existing pages.
		$cache = new JCCache(
			$this->newJCTitle( $this->getNonexistingTestPage( 'Foobar' ) ),
			null
		);

		$result = $cache->get();
		$this->assertFalse( $result );
	}

	private function getCacheKeyAndCacheObjectForTesting( $cache ): array {
		$jcCache = TestingAccessWrapper::newFromObject( $cache );
		// We want to ensure there is no cache entry for this key.
		$cacheKey = $jcCache->key;
		$wanCache = $jcCache->cache;
		$content = $jcCache->content;

		return [ $cacheKey, $wanCache, $content ];
	}

	public function testGetWithCacheDisabled(): void {
		$this->overrideConfigValue( 'JsonConfigDisableCache', true );

		$cache = $this->newJCCache();
		$result = $cache->get();

		[ $cacheKey, $wanCache ] = $this->getCacheKeyAndCacheObjectForTesting( $cache );

		$this->assertFalse( $wanCache->get( $cacheKey ) );
		$this->assertSame(
			'Test content for JCCacheTest-newJCCache',
			$result
		);
	}

	public function testGetWithCacheEnabled(): void {
		$cache = $this->newJCCache();
		$result = $cache->get();

		[ $cacheKey, $wanCache ] = $this->getCacheKeyAndCacheObjectForTesting( $cache );

		// The value returned as $results should be the same value that matches
		// the key in the cache.
		$this->assertSame(
			'Test content for JCCacheTest-newJCCache',
			$wanCache->get( $cacheKey )
		);
		$this->assertSame(
			'Test content for JCCacheTest-newJCCache',
			$result
		);
	}

	public function testGetWithContentOverrideAndCacheEnabled(): void {
		$cache = $this->newJCCache( true );
		$result = $cache->get();

		[ $cacheKey, $wanCache ] = $this->getCacheKeyAndCacheObjectForTesting( $cache );

		// Content is supplied, shouldn't be cached.
		$this->assertFalse( $wanCache->get( $cacheKey ) );
		$this->assertSame( '{}', $result->getText() );
	}

	public function testGetWithContentOverrideAndCacheDisabled(): void {
		$this->overrideConfigValue( 'JsonConfigDisableCache', true );

		$cache = $this->newJCCache( true );
		$result = $cache->get();

		[ $cacheKey, $wanCache ] = $this->getCacheKeyAndCacheObjectForTesting( $cache );

		// Content is supplied, shouldn't be cached.
		$this->assertFalse( $wanCache->get( $cacheKey ) );
		$this->assertSame( '{}', $result->getText() );
	}

	/**
	 * This test validates that what is stored in the cache as a
	 * result of fetchContent() is what gets stored in the class
	 * content property.
	 *
	 * @return void
	 */
	public function testGetCacheMatchesClassContentProp(): void {
		$cache = $this->newJCCache();
		$cachedContent = $cache->get();

		[ $cacheKey, $wanCache, $content ] = $this->getCacheKeyAndCacheObjectForTesting( $cache );

		$expected = 'Test content for JCCacheTest-newJCCache';

		// Cache hit and content should still be set below.
		$this->assertSame( $expected, $wanCache->get( $cacheKey ) );
		$this->assertSame( $expected, $cachedContent );
		// Content in-memory of the JCClass object.
		$this->assertSame( $expected, $content );
	}

}
