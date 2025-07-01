<?php

namespace JsonConfig\Tests\Integration;

use JsonConfig\JCCache;
use JsonConfig\JCContent;
use JsonConfig\JCTitle;
use MediaWiki\Page\WikiPage;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\EmptyBagOStuff;

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

	/** @return JCCache|\PHPUnit\Framework\MockObject\MockObject */
	private function newJCCache( WikiPage $page, bool $enableOverride = false, ?callable &$load = null ) {
		$title = $this->newJCTitle( $page );
		$content = $enableOverride
			? new JCContent( '{ "foo": 1000 }', 'Test.Example', false )
			: null;
		if ( $load === null ) {
			return new JCCache( $title, $content );
		} else {
			$store = $this->getMockBuilder( JCCache::class )
				->setConstructorArgs( [ $title, $content ] )
				->onlyMethods( [ 'loadLocal' ] )
				->getMock();

			$store->method( 'loadLocal' )
				->willReturnCallback( static fn () => $load() );

			return $store;
		}
	}

	public function testGetDefault() {
		// Default options (no content override), real page, real cache, real db load.
		$store = $this->newJCCache( $this->getExistingTestPage() );
		$this->assertSame( 'Test content for JCCacheTest-testGetDefault', $store->get() );
	}

	public function testGetWithNonExistingPage() {
		$store = $this->newJCCache( $this->getNonExistingTestPage() );
		$this->assertFalse( $store->get() );
	}

	public function testGetWithContentOverride() {
		$page = $this->getExistingTestPage();
		$store = $this->newJCCache( $page, true );

		$result = $store->get();
		$this->assertInstanceOf( JCContent::class, $result );
		$this->assertSame( '{ "foo": 1000 }', $result->getText() );

		// Overrides must not have leaked into a shared cache.
		$store = $this->newJCCache( $page, false );
		$this->assertSame( 'Test content for JCCacheTest-testGetWithContentOverride', $store->get() );

		// Override must work regardless of caching.
		$this->overrideConfigValue( 'JsonConfigDisableCache', true );
		$store = $this->newJCCache( $page, true );
		$result = $store->get();
		$this->assertInstanceOf( JCContent::class, $result );
		$this->assertSame( '{ "foo": 1000 }', $result->getText() );
	}

	public function testGetProcessCache() {
		// Mock main cache so we can differentiate between main and process cache hit.
		$this->setMainCache( new EmptyBagOStuff() );
		$called = 0;
		$mockLoad = static function () use ( &$called ) {
			$called++;
			return "Content from load call $called";
		};
		$page = $this->getExistingTestPage();
		// These represent Requests A and B
		$storeA = $this->newJCCache( $page, false, $mockLoad );
		$storeB = $this->newJCCache( $page, false, $mockLoad );

		$this->assertSame( 'Content from load call 1', $storeA->get(), 'Req A miss' );
		$this->assertSame( 'Content from load call 2', $storeB->get(), 'Req B miss' );
		$this->assertSame( 2, $called );

		$called = 0;
		$this->assertSame( 'Content from load call 1', $storeA->get(), 'Req A hit' );
		$this->assertSame( 'Content from load call 2', $storeB->get(), 'Req B hit' );
		$this->assertSame( 0, $called, 'Process cache hit' );

		// Again, with with a non-existing page
		$called = 0;
		$mockLoad = static function () use ( &$called ) {
			$called++;
			return false;
		};
		$page = $this->getNonExistingTestPage();
		$storeA = $this->newJCCache( $page, false, $mockLoad );
		$storeB = $this->newJCCache( $page, false, $mockLoad );

		$this->assertFalse( $storeA->get() );
		$this->assertSame( 1, $called, 'Req A miss' );
		$this->assertFalse( $storeB->get() );
		$this->assertSame( 2, $called, 'Req B miss' );

		$called = 0;
		$this->assertFalse( $storeA->get() );
		$this->assertFalse( $storeB->get() );
		$this->assertSame( 0, $called, 'Process cache hit' );
	}

	public function testGetMainCache() {
		$called = 0;
		$mockLoad = static function () use ( &$called ) {
			$called++;
			return "Content from load call $called";
		};
		$page = $this->getExistingTestPage();
		// Request A and B: Separate instances, so they don't share a process cache.
		$storeA = $this->newJCCache( $page, false, $mockLoad );
		$storeB = $this->newJCCache( $page, false, $mockLoad );

		$this->assertSame( 'Content from load call 1', $storeA->get(), 'Req A miss' );
		$this->assertSame( 'Content from load call 1', $storeB->get(), 'Req B hit' );
		$this->assertSame( 1, $called );

		// Again, with with a non-existing page
		$called = 0;
		$mockLoad = static function () use ( &$called ) {
			$called++;
			return false;
		};
		$page = $this->getNonExistingTestPage();
		$storeA = $this->newJCCache( $page, false, $mockLoad );
		$storeB = $this->newJCCache( $page, false, $mockLoad );

		$this->assertFalse( $storeA->get(), 'Req A miss' );
		$this->assertFalse( $storeB->get(), 'Req B hit' );
		$this->assertSame( 1, $called );
	}

	public function testGetWithMainCacheDisabled() {
		$this->overrideConfigValue( 'JsonConfigDisableCache', true );

		// Same as testGetMainCache, but expect a second load as we can't share between instances.
		$called = 0;
		$mockLoad = static function () use ( &$called ) {
			$called++;
			return "Content from load call $called";
		};
		$page = $this->getExistingTestPage();
		$storeA = $this->newJCCache( $page, false, $mockLoad );
		$storeB = $this->newJCCache( $page, false, $mockLoad );

		$this->assertSame( 'Content from load call 1', $storeA->get(), 'Req A miss' );
		$this->assertSame( 'Content from load call 2', $storeB->get(), 'Req B miss' );
		$this->assertSame( 2, $called );

		$called = 0;
		$this->assertSame( 'Content from load call 1', $storeA->get(), 'Req A hit' );
		$this->assertSame( 'Content from load call 2', $storeB->get(), 'Req B hit' );
		$this->assertSame( 0, $called, 'Process cache unaffected by wgJsonConfigDisableCache' );
	}
}
