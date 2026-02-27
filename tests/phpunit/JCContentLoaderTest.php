<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

use MediaWiki\Extension\JsonConfig\JCApiUtils;
use MediaWiki\Extension\JsonConfig\JCContentLoader;
use MediaWiki\Extension\JsonConfig\JCContentWrapper;
use MediaWiki\Extension\JsonConfig\JCSingleton;
use MediaWiki\Extension\JsonConfig\JCTransform;
use MediaWiki\Extension\JsonConfig\JCTransformer;
use MediaWiki\Status\Status;

/**
 * @covers \JsonConfig\JCContentLoader
 * @group Database
 */
class JCContentLoaderTest extends JCTransformTestCase {
	/**
	 * @dataProvider provideTestCases
	 * @param string $tab
	 * @param ?array $trans
	 * @param string $expectedFile
	 */
	public function testLoader( $tab, $trans, $expectedFile ) {
		$jct = JCSingleton::parseTitle( $tab, NS_DATA );
		$transform = $trans ? new JCTransform( $trans[0], $trans[1], $trans[2] ) : null;

		$json = file_get_contents( __DIR__ . '/transforms/' . $expectedFile );
		$expected = json_decode( $json );
		$wrapperStatus = JCContentWrapper::newFromJson( $jct, $expected );
		$this->assertStatusOK( $wrapperStatus );

		$wrapper = $wrapperStatus->getValue();
		$remoteStatus = Status::newGood( [
			'jsontransform' => json_decode( $json, true )
		] );

		// For local tests
		$transformer = $this->createPartialMock( JCTransformer::class, [ 'execute' ] );
		$transformer->method( 'execute' )->willReturn( $wrapperStatus );

		// For remote tests
		$utils = $this->createPartialMock( JCApiUtils::class, [ 'initApiRequestObj', 'callApiStatus' ] );
		$req = (object)[];
		$utils->method( 'initApiRequestObj' )->willReturn( $req );
		$utils->method( 'callApiStatus' )->willReturn( $remoteStatus );

		$loader = new JCContentLoader( $transformer, $utils );
		$loader->title( $jct );
		if ( $transform ) {
			$loader->transform( $transform );
		}
		$status = $loader->load();
		$this->assertStatusOK( $status, 'local fetch' );
		$this->assertEquals( $expected, $status->getValue()->toJson(), 'local fetch' );

		$config = clone $jct->getConfig();
		$config->store = null;
		$config->remote = (object)[
			'url' => 'http://example.com/fake-test',
		];
		$loader = new JCContentLoader( $transformer, $utils );
		$loader->title( $jct );
		if ( $transform ) {
			$loader->transform( $transform );
		}
		$status = $loader->load();
		$this->assertStatusOK( $status, 'local fetch' );
		$this->assertEquals( $expected, $status->getValue()->toJson(), 'remote fetch' );
	}

	public static function provideTestCases() {
		return [
			[
				'Sample_input.tab', // title
				null, // transform
				'output-none.json', // expected
			],
			[
				'Sample_input.tab', // title
				[
					'JCTransform_samples',
					'identity',
					[]
				],
				'output-identity.json', // expected
			],
			[
				'Sample_input.tab',
				[
					'JCTransform_samples',
					'prepend',
					[ 'Second_input.tab' ],
				],
				'output-prepend.json',
			]
		];
	}

	public function testMutability() {
		$inFile = 'Sample_input.tab';
		$jct = JCSingleton::parseTitle( $inFile, NS_DATA );
		$transform = new JCTransform( 'JCTransform samples', 'double', [] );
		$identity = json_decode( file_get_contents( __DIR__ . '/transforms/output-plain.json' ) );
		$double = json_decode( file_get_contents( __DIR__ . '/transforms/output-double.json' ) );

		$status = JCSingleton::getContentLoader( $jct )
			->load();
		$this->assertStatusOK( $status, 'non-transformed 1' );
		$plain1 = $status->getValue()->toJson();

		$status = JCSingleton::getContentLoader( $jct )
			->transform( $transform )
			->load();
		$this->assertStatusOK( $status, 'transformed' );
		$transformed = $status->getValue()->toJson();

		$status = JCSingleton::getContentLoader( $jct )
			->load();
		$this->assertStatusOK( $status, 'non-transformed 2' );
		$plain2 = $status->getValue()->toJson();

		$this->assertEquals( $identity, $plain1, 'plain1 run should be identical' );
		$this->assertEquals( $double, $transformed, 'transform run should be expected' );
		$this->assertEquals( $identity, $plain2, 'plain2 run should be identical' );
	}
}
