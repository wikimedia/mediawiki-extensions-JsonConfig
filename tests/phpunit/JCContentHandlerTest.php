<?php

namespace JsonConfig\Tests\Integration;

use JsonConfig\JCContentHandler;
use JsonConfig\JCTabularContent;
use MediaWiki\Parser\ParserOutput;

/**
 * @covers \JsonConfig\JCContentHandler
 */
class JCContentHandlerTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideCategoryData
	 */
	public function testAddCategoriesToParserOutput(
		array $jsonCategories,
		array $sortKeys,
		array $expectedCategories
	) {
		$handler = new JCContentHandler( 'JsonConfig/TestContent' );

		$content = new JCTabularContent(
			json_encode( [ 'mediawikiCategories' => $jsonCategories ] ),
			'TestJsonData',
			true
		);

		$parserOutput = new ParserOutput();

		$reflector = new \ReflectionClass( $handler );

		$method = $reflector->getMethod( 'addCategoriesToParserOutput' );
		$method->setAccessible( true );
		$method->invoke( $handler, $content, $parserOutput );

		$this->assertSame( $expectedCategories, $parserOutput->getCategoryNames() );

		foreach ( $sortKeys as $categoryName => $sortKey ) {
			$categorySortKey = $parserOutput->getCategorySortKey( $categoryName );
			$this->assertSame( $sortKey, $categorySortKey );
		}
	}

	public static function provideCategoryData(): array {
		return [
			'categories' => [
				[
					[ 'name' => 'Dog' ],
					[ 'name' => 'Cat' ]
				],
				[
					'Dog' => '',
					'Cat' => ''
				],
				[ 'Dog', 'Cat' ]
			],
			'categories with sort keys' => [
				[
					[ 'name' => 'Dog', 'sort' => 'Canine' ],
					[ 'name' => 'Cat', 'sort' => 'Feline' ]
				],
				[
					'Dog' => 'Canine',
					'Cat' => 'Feline'
				],
				[ 'Dog', 'Cat' ]
			]
		];
	}
}
