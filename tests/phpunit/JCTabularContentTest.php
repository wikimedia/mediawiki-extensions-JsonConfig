<?php

namespace JsonConfig\Tests;

use JsonConfig\JCTabularContent;
use MediaWikiTestCase;

/**
 * @package JsonConfigTests
 * @group JsonConfig
 */
class ValidationTest extends MediaWikiTestCase {
	private $basePath;

	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );
		$this->basePath = __DIR__;
	}

	/**
	 * @dataProvider provideTestCases
	 * @param string $fileName
	 * @param bool $shouldFail
	 * @param bool $thorough
	 */
	public function testValidation( $fileName, $shouldFail, $thorough ) {

		$file = $this->basePath . '/' . $fileName;
		$content = file_get_contents( $file );
		if ( $content === false ) {
			$this->fail( "Can't read file $file" );
		}

		$c = new JCTabularContent( $content, 'Tabular.JsonConfig', $thorough );
		if ( $shouldFail ) {
			$this->assertFalse( $c->isValid(), 'Validation unexpectedly succeeded' );
		} else {
			if ( $c->isValid() ) {
				$this->assertTrue( true );
			} else {
				$this->fail( $c->getStatus()->getMessage()->plain() );
			}
		}
	}

	public function provideTestCases() {
		$result = [];

		foreach ( glob( "{$this->basePath}/tabular-good/*.json" ) as $file ) {
			$file = substr( $file, strlen( $this->basePath ) + 1 );
			$result[] = [ $file, false, false ];
			$result[] = [ $file, false, true ];
		}
		foreach ( glob( "{$this->basePath}/tabular-bad/*.json" ) as $file ) {
			$file = substr( $file, strlen( $this->basePath ) + 1 );
			$result[] = [ $file, true, true ];
		}

		return $result;
	}
}
