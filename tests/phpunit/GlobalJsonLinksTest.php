<?php

namespace JsonConfig\Tests;

use JsonConfig\GlobalJsonLinks;
use JsonConfig\JCSingleton;
use MediaWiki\Cache\GenderCache;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\TitleParser;
use MediaWiki\Title\TitleValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class GlobalJsonLinksTest extends MediaWikiIntegrationTestCase {

	/** @var array */
	private $configBackup;

	/** @var TitleParser */
	private $titleParser;

	protected function parseTitle( $titleStr ) {
		$t = $this->titleParser->parseTitle( $titleStr );
		if ( !$t ) {
			throw new Exception( 'invalid test data' );
		}
		return $t;
	}

	protected function parseDataTitle( $titleStr ) {
		$jct = JCSingleton::parseTitle( $titleStr, NS_DATA );
		if ( !$jct ) {
			throw new Exception( 'Invalid test data' );
		}
		return $jct;
	}

	protected function parseDataTitles( $arr ) {
		$out = [];
		foreach ( $arr as $titleStr ) {
			$out[] = $this->parseTitle( $titleStr );
		}
		return $out;
	}

	/**
	 * Returns a mock GenderCache that will consider a user "female" if the
	 * first part of the user name ends with "a" and "male" otherwise for
	 * grammatical purposes.
	 *
	 * Duplicated from MediaWikiTitleCodecTest
	 *
	 * @return GenderCache
	 */
	private function getGenderCache() {
		$genderCache = $this->createMock( GenderCache::class );

		$genderCache->method( 'getGenderOf' )
			->willReturnCallback( static function ( $userName ) {
				return preg_match( '/^[^- _]+a( |_|$)/u', $userName ) ? 'female' : 'male';
			} );

		return $genderCache;
	}

	protected function setUp(): void {
		parent::setUp();

		// Copied from mediawiki/tests/phpunit/includes/title/MediaWikiTitleCodecTest.php
		$this->overrideConfigValues( [
			MainConfigNames::DefaultLanguageVariant => false,
			MainConfigNames::MetaNamespace => 'Project',
			MainConfigNames::LocalInterwikis => [ 'localtestiw' ],
			MainConfigNames::CapitalLinks => false,
		] );
		$this->setUserLang( 'pl' );
		$this->setContentLang( 'pl' );
		$this->setService( 'GenderCache', $this->getGenderCache() );

		JCSingleton::getTitleMap(); // Initialize internal Init() flag
		$this->configBackup = [ JCSingleton::$titleMap, JCSingleton::$namespaces ];

		[ JCSingleton::$titleMap, JCSingleton::$namespaces ] =
			JCSingleton::parseConfiguration( [ 'modelForNs0', 'modelForNs1' ],
				[ 'globalModel' => 'something' ], [
					'model1' => [
						'nsName' => 'Data',
						'namespace' => 486,
						'pattern' => '/.\.tab$/',
					],
					'model2' => [
						'nsName' => 'Data',
						'namespace' => 486,
						'pattern' => '/.\.chart$/',
					],
				], [
					'model1' => null,
					'model2' => null,
					'globalModel' => 'conflicts with JsonConfig models',
				] );

		$this->titleParser = $this->getServiceContainer()->getTitleParser();
	}

	protected function tearDown(): void {
		parent::tearDown();
		[ JCSingleton::$titleMap, JCSingleton::$namespaces ] = $this->configBackup;
	}

	/**
	 * @param string $wiki wiki id
	 * @return GlobalJsonLinks
	 */
	private function globalJsonLinks( $wiki ) {
		return TestingAccessWrapper::newFromObject(
			$this->getServiceContainer()
				->getService( 'JsonConfig.GlobalJsonLinks' )
				->forWiki( $wiki )
		);
	}

	/**
	 * @dataProvider provideWikis
	 * @covers \JsonConfig\GlobalJsonLinks::mapWiki
	 */
	public function testMapWiki( $a, $b, $shouldMatch ) {
		[ $wikiA, $nsA, $nsTextA, $titleA ] = $a;
		$gjl = $this->globalJsonLinks( $wikiA );
		$tvA = new TitleValue( $nsA, $titleA );
		$idA = $gjl->mapWiki( $tvA );
		$savedA = $gjl->getWiki( $idA );

		[ $wikiB, $nsB, $nsTextB, $titleB ] = $b;
		$gjl = $this->globalJsonLinks( $wikiB );
		$tvB = new TitleValue( $nsB, $titleB );
		$idB = $gjl->mapWiki( $tvB );
		$savedB = $gjl->getWiki( $idB );

		$this->assertTrue( $idA > 0, "id should be non-zero" );
		$this->assertTrue( $idB > 0, "id should be non-zero" );

		$this->assertSame( $wikiA, $savedA['wiki'], "namespace should match" );
		$this->assertSame( $wikiB, $savedB['wiki'], "namespace should match" );

		$this->assertSame( $nsA, $savedA['namespace'], "namespace should match" );
		$this->assertSame( $nsB, $savedB['namespace'], "namespace should match" );

		$this->assertSame( $nsTextA, $savedA['namespaceText'], "namespace text should match" );
		$this->assertSame( $nsTextB, $savedB['namespaceText'], "namespace text should match" );

		$backA = $gjl->getLinksFromPage( $tvA );
		$backB = $gjl->getLinksFromPage( $tvB );

		if ( $shouldMatch ) {
			$this->assertSame( $idA, $idB, 'wiki/ns ids should match' );
		} else {
			$this->assertNotSame( $idA, $idB, 'wiki/ns ids should not match' );
		}
	}

	public static function provideWikis() {
		return [
			[
				[ 'enwiki', NS_MAIN, '', 'Foobar' ],
				[ 'enwiki', NS_MAIN, '', 'Bizbax' ],
				true // should match
			],
			[
				[ 'dewiki', NS_MAIN, '', 'Foobar' ],
				[ 'dewiki', NS_MAIN, '', 'Bizbax' ],
				true // should match
			],
			[
				[ 'enwiki', NS_MAIN, '', 'Foobar' ],
				[ 'dewiki', NS_MAIN, '', 'Foobar' ],
				false // should not match
			],
			[
				[ 'enwiki', NS_MAIN, '', 'Bizbax' ],
				[ 'dewiki', NS_MAIN, '', 'Bizbax' ],
				false // should not match
			],
			[
				[ 'plwiki', NS_MAIN, '', 'Foobar' ],
				[ 'plwiki', NS_TALK, 'Dyskusja', 'Foobar' ],
				false // should not match
			],
			[
				[ 'plwiki', NS_TALK, 'Dyskusja', 'Foobar' ],
				[ 'plwiki', NS_TALK, 'Dyskusja', 'Bizbax' ],
				true // should match
			],
			[
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkownika', 'DefaultUser1' ],
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkownika', 'DefaultUser2' ],
				true // should match
			],
			[
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkowniczki', 'Barbara' ],
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkowniczki', 'Marta' ],
				true // should match
			],
			[
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkownika', 'Oleg' ],
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkownika', 'Tomasz' ],
				true // should match
			],
			[
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkowniczki', 'Barbara' ],
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkownika', 'Oleg' ],
				false // should not match
			],
			[
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkownika', 'Tomasz' ],
				[ 'plwiki', NS_USER_TALK, 'Dyskusja_użytkowniczki', 'Marta' ],
				false // should not match
			],
		];
	}

	/**
	 * @dataProvider provideTargets
	 * @covers \JsonConfig\GlobalJsonLinks::mapTargets
	 */
	public function testMapTargets( $a, $b, $shouldMatch ) {
		$wiki = 'enwiki';
		$gjl = $this->globalJsonLinks( $wiki );

		$mapA = $gjl->mapTargets( $a );
		ksort( $mapA );

		$mapB = $gjl->mapTargets( $b );
		ksort( $mapB );

		if ( $shouldMatch ) {
			$this->assertSame( $mapA, $mapB );
		} else {
			$this->assertNotSame( $mapA, $mapB );
		}
	}

	public static function provideTargets() {
		return [
			[
				[ 'Sample_1.tab' ],
				[ 'Sample_1.tab' ],
				true // should match
			],
			[
				[ 'Sample_1.tab' ],
				[ 'Sample_2.tab' ],
				false // should not match
			],
			[
				[ 'Sample_1.tab', 'Sample_2.tab' ],
				[ 'Sample_1.tab', 'Sample_2.tab' ],
				true // should match
			],
			[
				[ 'Sample_2.tab', 'Sample_1.tab' ],
				[ 'Sample_1.tab', 'Sample_2.tab' ],
				true // should match
			],
		];
	}

	/**
	 * @dataProvider provideLinks
	 * @covers \JsonConfig\GlobalJsonLinks::insertLinks
	 * @covers \JsonConfig\GlobalJsonLinks::getLinksToTarget
	 */
	public function testInsertLinks( $targetStr, $sources, $expected ) {
		$target = $this->parseDataTitle( $targetStr );
		$ticket = 'xyz';

		foreach ( $sources as $source ) {
			[ $wiki, $titles ] = $source;
			$gjl = $this->globalJsonLinks( $wiki );
			foreach ( $titles as $text ) {
				$title = $this->parseTitle( $text );
				$gjl->insertLinks( $title, [ $targetStr ], $ticket );
			}
		}

		$wiki = 'commonswiki';
		$gjl = $this->globalJsonLinks( $wiki );
		$links = $gjl->getLinksToTarget( $target );

		$this->assertSame( $expected, $links );
	}

	/**
	 * @dataProvider provideLinks
	 * @covers \JsonConfig\GlobalJsonLinks::updateLinks
	 * @covers \JsonConfig\GlobalJsonLinks::getLinksToTarget
	 */
	public function testUpdateLinks( $targetStr, $sources, $expected ) {
		$target = $this->parseDataTitle( $targetStr );
		$ticket = 'xyz';

		// Force namespace name tracking off to test migration
		$this->overrideConfigValues( [
			'TrackGlobalJsonLinksNamespaces' => false,
		] );

		foreach ( $sources as $source ) {
			[ $wiki, $titles ] = $source;
			$gjl = $this->globalJsonLinks( $wiki );
			foreach ( $titles as $text ) {
				$title = $this->parseTitle( $text );
				$gjl->updateLinks( $title, [ $targetStr ], $ticket );
			}
		}

		$wiki = 'commonswiki';
		$gjl = $this->globalJsonLinks( $wiki );
		$links = $gjl->getLinksToTarget( $target );

		$canonicalNamespaces = $this->getServiceContainer()->getNamespaceInfo()->getCanonicalNamespaces();
		$filtered = [];
		foreach ( $expected as $row ) {
			$row['namespaceText'] = $canonicalNamespaces[$row['namespace']];
			$filtered[] = $row;
		}
		$this->assertSame( $filtered, $links );

		// And force namespaces back on.
		$this->overrideConfigValues( [
			'TrackGlobalJsonLinksNamespaces' => true,
		] );

		foreach ( $sources as $source ) {
			[ $wiki, $titles ] = $source;
			$gjl = $this->globalJsonLinks( $wiki );
			foreach ( $titles as $text ) {
				$title = $this->parseTitle( $text );
				$gjl->updateLinks( $title, [ $targetStr ], $ticket );
			}
		}

		$wiki = 'commonswiki';
		$gjl = $this->globalJsonLinks( $wiki );
		$links = $gjl->getLinksToTarget( $target );

		$this->assertSame( $expected, $links );
	}

	public static function provideLinks() {
		return [
			[
				'Sample_1.tab',
				[ /* pages */ ],
				[ /* expected links */ ]
			],
			[
				'Sample_1.tab',
				[
					[ 'enwiki', [ 'London' ] ]
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'London' ],
				]
			],
			[
				'Sample_1.tab',
				[
					[ 'enwiki', [ 'Warsaw', 'London' ] ]
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'London' ],
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'Warsaw' ],
				]
			],
			[
				'Sample_1.tab',
				[
					[ 'enwiki', [ 'Warsaw', 'London' ] ],
					[ 'plwiki', [ 'Warszawa', 'Londyn' ] ]
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'London' ],
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'Warsaw' ],
					[ 'wiki' => 'plwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'Londyn' ],
					[ 'wiki' => 'plwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'Warszawa' ],
				]
			],
			[
				'Sample_1.tab',
				[
					[ 'plwiki', [ 'Warszawa' ] ],
					[ 'plwiki', [ 'Dyskusja:Warszawa' ] ]
					// note -- this test instance will be configured to polish all around, see setUp
				],
				[
					[ 'wiki' => 'plwiki', 'namespace' => 0, 'namespaceText' => '', 'title' => 'Warszawa' ],
					[ 'wiki' => 'plwiki', 'namespace' => 1, 'namespaceText' => 'Dyskusja', 'title' => 'Warszawa' ],
				]
			]
		];
	}

	/**
	 * @dataProvider provideBatchQueryOffset
	 * @covers \JsonConfig\GlobalJsonLinks::batchQuery
	 * @covers \JsonConfig\GlobalJsonLinksQuery::setOffset
	 * @covers \JsonConfig\GlobalJsonLinksQuery::validateOffsetArray
	 * @covers \JsonConfig\GlobalJsonLinksQuery::hasOffset
	 */
	public function testBatchQueryOffset( $offset, $expected ) {
		$gjl = $this->globalJsonLinks( WikiMap::getCurrentWikiId() );
		$batch = $gjl->batchQuery( new TitleValue( NS_DATA, 'Test.tab' ) );
		$result = $batch->setOffset( $offset );
		$this->assertSame( $expected, $result );
		$this->assertSame( $expected, $batch->hasOffset() );
	}

	public static function provideBatchQueryOffset() {
		return [
			[ '', false ],
			[ '1', false ],
			[ '1|2', false ],
			[ '1|2|3', false ],
			[ '1|2|3|4', false ],
			[ '1|2|3|4|5', true ],
			[ '1|2|3|4|5|6', false ]
		];
	}
}
