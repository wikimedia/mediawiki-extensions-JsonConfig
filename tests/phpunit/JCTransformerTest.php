<?php

namespace JsonConfig\Tests;

use JsonConfig\JCSingleton;
use JsonConfig\JCTabularContent;
use JsonConfig\JCTransform;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;

/**
 * @covers \JsonConfig\JCTransformer
 * @group Database
 */
class JCTransformerTest extends JCTransformTestCase {
	/**
	 * @dataProvider provideTestCases
	 * @param string $module
	 * @param string $func
	 * @param array $args
	 * @param string $inFile
	 * @param string $expectedFile
	 */
	public function testFilterData( $module, $func, $args, $inFile, $expectedFile ) {
		$inJson = file_get_contents( __DIR__ . '/transforms/' . $inFile . ".json" );
		$content = new JCTabularContent( $inJson, 'Tabular.JsonConfig', true );
		if ( $content->isValid() ) {
			$this->assertTrue( true );
		} else {
			$this->fail( $content->getStatus()->getMessage()->plain() );
		}

		if ( $expectedFile ) {
			$expectedJson = file_get_contents( __DIR__ . '/transforms/' . $expectedFile );
			$expected = FormatJson::decode( $expectedJson );
		} else {
			$expected = null;
		}
		$filter = new JCTransform( $module, $func, $args );

		$title = JCSingleton::parseTitle( $inFile, NS_DATA );
		$transformer = $this->getServiceContainer()->getService( 'JsonConfig.Transformer' );
		$status = $transformer->execute( $title, $content, $filter );

		if ( $expectedFile ) {
			if ( $status->isOk() ) {
				$out = $status->getValue();
				$this->assertEquals( $expected, $out->toJson(), 'execution got these results' );
			} else {
				$services = MediaWikiServices::getInstance();
				$context = RequestContext::getMain();
				$messageFormatter = $services->getFormatterFactory()->getStatusFormatter( $context );
				$this->fail( 'failed to execute' . json_encode( $messageFormatter->getWikiText( $status ) ) );
			}
		} else {
			if ( $status->isOk() ) {
				$this->fail( 'Expected filter execution to fail, but it succeeded' );
			} else {
				$this->assertTrue( true, 'Filter execution fails as expected' );
			}
		}
	}

	public static function provideTestCases() {
		return [
			[
				'JCTransform_samples',
				'fails',
				[ 'n/a' ],
				'Sample_input.tab',
				null,
			],
			[
				'JCTransform_samples',
				'identity',
				[],
				'Sample_input.tab',
				'output-identity.json',
			],
			[
				'JCTransform_samples',
				'select_columns',
				[ 'date', 'liberal' ],
				'Sample_input.tab',
				'output-select-columns.json',
			],
			[
				'JCTransform_samples',
				'field_equals',
				[ 'date', '1993-09-30' ],
				'Sample_input.tab',
				'output-field-equals.json',
			],
			[
				'JCTransform_samples',
				'sum_columns',
				[
					'field' => 'total',
					'title:en' => 'Total votes',
					'title:fr' => 'Totale de votes',
					'columns' => 'pc,liberal,ndp,bq,reform'
				],
				'Sample_input.tab',
				'output-sum-columns.json',
			],
			[
				'JCTransform_samples',
				'prepend',
				[ 'Second_input.tab' ],
				'Sample_input.tab',
				'output-prepend.json',
			]
		];
	}
}
