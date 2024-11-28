<?php

namespace JsonConfig\Tests;

use JsonConfig\GlobalJsonLinks;
use JsonConfig\JCSingleton;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
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

	protected function setUp(): void {
		parent::setUp();

		// Copied from mediawiki/tests/phpunit/includes/title/MediaWikiTitleCodecTest.php
		$this->overrideConfigValues( [
			MainConfigNames::DefaultLanguageVariant => false,
			MainConfigNames::MetaNamespace => 'Project',
			MainConfigNames::LocalInterwikis => [ 'localtestiw' ],
			MainConfigNames::CapitalLinks => false,
		] );
		$this->setUserLang( 'en' );
		$this->setContentLang( 'en' );

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

		$this->titleParser = MediaWikiServices::getInstance()->getTitleParser();
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
		$gjl = $this->globalJsonLinks( $a );
		$idA = $gjl->mapWiki();

		[ $wiki ] = $b;
		$gjl = $this->globalJsonLinks( $b );
		$idB = $gjl->mapWiki();

		if ( $shouldMatch ) {
			$this->assertSame( $idA, $idB );
		} else {
			$this->assertNotSame( $idA, $idB );
		}
	}

	public static function provideWikis() {
		return [
			[
				'enwiki', // wiki
				'enwiki', // second set
				true // should match
			],
			[
				'dewiki',
				'dewiki',
				true // should match
			],
			[
				'enwiki',
				'dewiki',
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

		foreach ( $sources as $source ) {
			[ $wiki, $titles ] = $source;
			$gjl = $this->globalJsonLinks( $wiki );
			foreach ( $titles as $text ) {
				// todo: this doesn't know non-english namespaces for testing
				// on the test wiki atm
				$title = $this->parseTitle( $text );
				$gjl->insertLinks( $title, [ $targetStr ] );
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
					[ 'enwiki', [ 'Paris' ] ]
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'title' => 'Paris' ],
				]
			],
			[
				'Sample_1.tab',
				[
					[ 'enwiki', [ 'Paris', 'London' ] ]
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'title' => 'London' ],
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'title' => 'Paris' ],
				]
			],
			[
				'Sample_1.tab',
				[
					[ 'enwiki', [ 'Paris', 'London' ] ],
					[ 'frwiki', [ 'Paris', 'Londres' ] ]
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'title' => 'London' ],
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'title' => 'Paris' ],
					[ 'wiki' => 'frwiki', 'namespace' => 0, 'title' => 'Londres' ],
					[ 'wiki' => 'frwiki', 'namespace' => 0, 'title' => 'Paris' ],
				]
			],
			[
				'Sample_1.tab',
				[
					[ 'enwiki', [ 'Paris' ] ],
					[ 'enwiki', [ 'Talk:Paris' ] ]
					// todo -- this test wiki doesn't know the right namespaces for local insertion of Discussion: ns
				],
				[
					[ 'wiki' => 'enwiki', 'namespace' => 0, 'title' => 'Paris' ],
					[ 'wiki' => 'enwiki', 'namespace' => 1, 'title' => 'Paris' ],
				]
			]
		];
	}

}
