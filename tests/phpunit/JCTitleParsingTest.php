<?php
namespace JsonConfig\Tests;

use Exception;
use JsonConfig\JCSingleton;
use MediaWikiTestCase;

/**
 * @package JsonConfigTests
 * @group JsonConfig
 * @group Title
 * @group Database
 */
class JCTitleParsingTest extends MediaWikiTestCase {

	private $configBackup;

	public function setUp() {
		parent::setUp();

		// Copied from mediawiki/tests/phpunit/includes/title/MediaWikiTitleCodecTest.php
		$this->setMwGlobals( [
			'wgDefaultLanguageVariant' => false,
			'wgMetaNamespace' => 'Project',
			'wgLocalInterwikis' => [ 'localtestiw' ],
			'wgCapitalLinks' => true,

			// Make sure we use the matching prefix
			'wgJsonConfigInterwikiPrefix' => 'remotetestiw',

			// NOTE: this is why global state is evil.
			// TODO: refactor access to the interwiki codes so it can be injected.
			'wgHooks' => [
				'InterwikiLoadPrefix' => [
					function ( $prefix, &$data ) {
						if ( $prefix === 'localtestiw' ) {
							$data = [ 'iw_url' => 'localtestiw' ];
						} elseif ( $prefix === 'remotetestiw' ) {
							$data = [ 'iw_url' => 'remotetestiw' ];
						}
						return false;
					}
				]
			]
		] );
		$this->setUserLang( 'en' );
		$this->setContentLang( 'en' );

		JCSingleton::getTitleMap(); // Initialize internal Init() flag
		$this->configBackup = [ JCSingleton::$titleMap, JCSingleton::$namespaces ];

		list( JCSingleton::$titleMap, JCSingleton::$namespaces ) =
			JCSingleton::parseConfiguration( [ 'modelForNs0', 'modelForNs1' ],
				[ 'globalModel' => 'something' ], [
					'model1' => [ 'nsName' => 'All', 'namespace' => 800 ],
					'model2' => [
						'nsName' => 'Dat',
						'namespace' => 900,
						'pattern' => '/^(lower|Capitalized|sub\/space|with:colon)$/'
					],
				], [
					'model1' => null,
					'model2' => null,
					'globalModel' => 'conflicts with JsonConfig models',
				] );
	}

	protected function tearDown() {
		parent::tearDown();
		list( JCSingleton::$titleMap, JCSingleton::$namespaces ) = $this->configBackup;
	}

	/** @dataProvider provideValues
	 * @param $value
	 * @param $ns
	 * @param bool|null|string $expected false if unrecognized namespace,
	 * and null if namespace is handled but does not match this title, string to match dbKey
	 * @throws Exception
	 */
	public function testTitleParsing( $value, $ns, $expected = false ) {

		$actual = JCSingleton::parseTitle( $value, $ns );
		if ( !$expected ) {
			$this->assertSame( $expected, $actual );
		} else {
			$this->assertInstanceOf( 'JsonConfig\JCTitle', $actual );
			$this->assertSame( $expected, $actual->getDBkey() );
			$this->assertSame( $ns, $actual->getNamespace() );
			$this->assertNotNull( $actual->getConfig() );
		}
	}

	public function provideValues() {
		return [
			// title, ns, expected
			[ false, null, false ],
			[ null, null, false ],
			[ '', null, false ],
			[ '_', 0, false ],

			// 800: any name is ok
			[ '_', 800, null ],
			[ ':a/b\d  e_a ', 800, 'a/b\d_e_a' ], // normalization
			[ 'wikipedia:ok', 800, 'wikipedia:ok' ],
			[ 'localtestiw:page', 800, 'localtestiw:page' ],

			// 900: only these names: lower|Capitalized|sub/space|with:colon
			[ '_', 900, null ],
			[ 'nope', 900, null ],
			[ 'Lower', 900, null ],
			[ 'lower', 900, 'lower' ],
			[ 'capitalized', 900, null ],
			[ 'Capitalized', 900, 'Capitalized' ],
			[ 'sub/space', 900, 'sub/space' ],
			[ 'with:colon', 900, 'with:colon' ],
		];
	}

}
