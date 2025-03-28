<?php

namespace JsonConfig\Tests;

use JsonConfig\JCSingleton;
use JsonConfig\JCTitle;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 */
class JCTitleParsingTest extends MediaWikiIntegrationTestCase {

	/** @var array */
	private $configBackup;

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
					'model1' => [ 'nsName' => 'All', 'namespace' => 800 ],
					'model2' => [
						'nsName' => 'Dat',
						'namespace' => 900,
						'pattern' => '/^(Capitalized|Sub\/space|With:colon)$/'
					],
				], [
					'model1' => null,
					'model2' => null,
					'globalModel' => 'conflicts with JsonConfig models',
				] );
	}

	protected function tearDown(): void {
		parent::tearDown();
		[ JCSingleton::$titleMap, JCSingleton::$namespaces ] = $this->configBackup;
	}

	/**
	 * @dataProvider provideValues
	 * @covers \JsonConfig\JCSingleton::parseTitle
	 */
	public function testTitleParsing( $value, $ns, $expected = false ) {
		$actual = JCSingleton::parseTitle( $value, $ns );
		if ( !$expected ) {
			$this->assertSame( $expected, $actual );
		} else {
			$this->assertInstanceOf( JCTitle::class, $actual );
			$this->assertSame( $expected, $actual->getDBkey() );
			$this->assertSame( $ns, $actual->getNamespace() );
			$this->assertNotNull( $actual->getConfig() );
		}
	}

	public static function provideValues() {
		return [
			// title, ns, expected
			[ false, null, false ],
			[ null, null, false ],
			[ '', null, false ],
			[ '_', 0, false ],

			// 800: any name is ok
			[ '_', 800, null ],
			[ ':a/b\d  e_a ', 800, 'A/b\d_e_a' ], // normalization
			[ 'wikipedia:ok', 800, 'Wikipedia:ok' ],
			[ 'localtestiw:page', 800, 'Localtestiw:page' ],

			// 900: only these names: lower|Capitalized|sub/space|with:colon
			[ '_', 900, null ],
			[ 'nope', 900, null ],
			[ 'capitalized', 900, 'Capitalized' ],
			[ 'Capitalized', 900, 'Capitalized' ],
			[ 'sub/space', 900, 'Sub/space' ],
			[ 'Sub/space', 900, 'Sub/space' ],
			[ 'with:colon', 900, 'With:colon' ],
			[ 'With:colon', 900, 'With:colon' ],

			// unusual inputs where the handling differs from normal MediaWiki title rules
			// (these cases are here just to document the behavior without endorsing it)
			[ '_foo', 800, 'Foo' ],
			[ ':foo', 800, 'Foo' ],
			[ ':_foo', 800, 'Foo' ],
			[ '_:foo', 800, 'Foo' ],
			[ '', 800, false ],
			[ ':', 800, null ],
			[ '_', 800, null ],
			[ ':_', 800, null ],
			[ '_:', 800, null ],
			[ 'foo#bar', 800, null ],
			[ '#bar', 800, null ],
		];
	}

}
