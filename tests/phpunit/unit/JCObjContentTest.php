<?php

namespace JsonConfig\Tests;

use JsonConfig\JCObjContent;
use JsonConfig\JCValidators;
use JsonConfig\JCValue;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Assert;

/**
 * @covers \JsonConfig\JCObjContent
 */
class JCObjContentTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideBasic */
	public function testBasic( string $text, ?bool $isValid ) {
		foreach ( [ false, true ] as $thorough ) {
			$msg = "'$text'" . ( $thorough ? '::thorough' : '::quick' );
			$c = new ObjContent( $text, null, $thorough );
			if ( $isValid === null ) {
				$this->assertFalse( $c->isValidJson(), $msg . '-invalid-json' );
			} else {
				$this->assertTrue( $c->isValidJson(), $msg . '-valid-json' );
				$this->assertSame( $isValid, $c->isValid(), $msg . '-isValid' );
			}
		}
	}

	public static function provideBasic() {
		return [
			[ '', null ],
			[ 'null', null ],
			[ 'bad', null ],
			[ '[]', false ],
			[ 'true', false ],
			[ 'false', false ],
			[ '""', false ],
			[ '"abc"', false ],
			[ '{}', true ],
		];
	}

	/**
	 * Also used provideValidationFirst as data provider
	 * @dataProvider provideValidation
	 */
	public function testValidation(
		string $message,
		string $initial,
		$expectedWithDflts,
		$expectedNoDflts,
		callable $validators,
		?int $errors = null
	) {
		if ( $expectedWithDflts === true ) {
			$expectedWithDflts = $initial;
		}
		if ( $expectedNoDflts === true ) {
			$expectedNoDflts = $expectedWithDflts;
		}
		foreach ( [ false, true ] as $th ) {
			$c = new ObjContent( $initial, $validators, $th );
			$msg = $message . '::' . ( $th ? 'thorough' : 'quick' ) . '::';
			if ( !$errors ) {
				$this->assertTrue( $c->isValid(), $msg . 'MUST-BE-VALID' );
			} else {
				$this->assertFalse( $c->isValid(), $msg . 'MUST-BE-INVALID' );
				$errCount = is_int( $errors ) ? $errors : count( $errors );
				$this->assertCount(
					$errCount, $c->getStatus()->getErrorsArray(), $msg . 'ERROR-COUNT'
				);
			}
			$expected = is_array( $expectedWithDflts )
				? $expectedWithDflts[(int)$th] : $expectedWithDflts;
			$this->assertJsonEquals( $expected, $c->getDataWithDefaults(), $msg . 'WITH-DEFAULTS' );

			if ( $expectedNoDflts ) {
				$expected = is_array( $expectedNoDflts )
					? $expectedNoDflts[(int)$th] : $expectedNoDflts;
			}
			$this->assertJsonEquals( $expected, $c->getData(), $msg . 'NO-DEFAULTS' );
		}
	}

	public static function provideValidation() {
		return array_merge( self::provideValidationFirst(), [

			// $message, $initial, $expectedWithDflts, $expectedNoDflts, $validators, $errors = null

			[
				'fldA', '{"fldA":5}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
				}
			],
			[
				'fldA caps', '{"flda":5}', '{"fldA":5}', true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
				}
			],
			[
				'int field in obj', '{"1":false}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( '1', JCValidators::isBool() );
				}
			],
			[
				'fldA twice', '{"fldA":5}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
					$o->test( 'fldA', JCValidators::isInt() );
				}
			],
			[
				'fldA twice caps', '{"flda":5}', '{"fldA":5}', true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
					$o->test( 'fldA', JCValidators::isInt() );
				}
			],
			[
				'fld/fldA=5', '{"fld":{"fldA":5}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fld', 'fldA' ], JCValidators::isInt() );
				}
			],
			[
				'twice fld/fldA=5', '{"fld":{"fldA":5}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fld', 'fldA' ], JCValidators::isInt() );
					$o->test( [ 'fld', 'fldA' ], JCValidators::isInt() );
				}
			],
			[
				'fld[0]=5', '{"fld":[5]}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fld', 0 ], JCValidators::isInt() );
				}
			],
			[
				'twice fld[0]=5', '{"fld":[5]}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fld', 0 ], JCValidators::isInt() );
					$o->test( [ 'fld', 0 ], JCValidators::isInt() );
				}
			],
			[
				'fld/fldA/fldB=5', '{"fld":{"fldA":{"fldB":5}}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fld', 'fldA', 'fldB' ], JCValidators::isInt() );
				}
			],
			[
				'opt fldA', '{}', '{"fldA":5}', '{}',
				static function ( JCObjContent $o ) {
					$o->testOptional( 'fldA', 5 );
				}
			],
			[
				'opt fld/fldA=5', '{}', '{"fld":{"fldA":5}}', '{"fld":{}}',
				static function ( JCObjContent $o ) {
					$o->testOptional( [ 'fld', 'fldA' ], 5 );
				}
			],
			[
				'opt fld[0]=5', '{}', '{"fld":[5]}', true,
				static function ( JCObjContent $o ) {
					$o->testOptional( [ 'fld', 0 ], 5 );
				}
			],
			[
				'opt fld/fldA/fldB=5', '{}', '{"fld":{"fldA":{"fldB":5}}}', '{"fld":{"fldA":{}}}',
				static function ( JCObjContent $o ) {
					$o->testOptional( [ 'fld', 'fldA', 'fldB' ], 5 );
				}
			],
			[
				'del missing fldA', '{}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::deleteField() );
				}
			],
			[
				'del sub-missing fldA/fldB', '{}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldA', 'fldB' ], JCValidators::deleteField() );
				}
			],
			[
				'del sub-missing fldA[0]', '{}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldA', 0 ], JCValidators::deleteField() );
				}
			],
			[
				'del fldA', '{"fldA":5}', '{}', true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::deleteField() );
				}
			],
			[
				'del fldA/fldB', '{"fldA":{"fldB":5}}', '{"fldA":{}}', true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldA', 'fldB' ], JCValidators::deleteField() );
				}
			],
			[
				'fldA->fldB', '{"fldA":5}', '{"fldB":5}', true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA',
						static function ( JCValue $v, array $path, JCObjContent $cn ) {
							$new = clone $v;
							$new->status( JCValue::CHECKED );
							$cn->getValidationData()->setField( 'fldB', $new );
							$v->status( JCValue::MISSING ); // delete this field
							return true;
						} );
				}
			],
			[
				'fldA/fldB->fldB', '{"fldA":{"fldB":5}}', '{"fldB":5}', true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldA', 'fldB' ],
						static function ( JCValue $v, array $path, JCObjContent $cn ) {
							$new = clone $v;
							$new->status( JCValue::CHECKED );
							$cn->getValidationData()->setField( 'fldB', $new );
							$v->status( JCValue::MISSING ); // delete this field
							return true;
						} );
					$o->test( 'fldA', JCValidators::deleteField() );
				}
			],
			[
				'fldA,fldB', '{"fldA":5,"fldB":true}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
					$o->test( 'fldB', JCValidators::isBool() );
				}
			],
			[
				'fldX/fldA,fldX/fldB', '{"fldX":{"fldA":5,"fldB":true}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldX', 'fldA' ], JCValidators::isInt() );
					$o->test( [ 'fldX', 'fldB' ], JCValidators::isBool() );
				}
			],
			[
				'fldA[0],opt fldA[1]', '{"fldA":[5]}', '{"fldA":[5,true]}', true,
				static function ( JCObjContent $o ) {
					$o->testOptional( [ 'fldA', 1 ], true, JCValidators::isBool() );
					$o->test( [ 'fldA', 0 ], JCValidators::isInt() );
				}
			],
			[
				'chk parent, child', '{"fldA":{"fldB":5}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldA' ], JCValidators::isDictionary() );
					$o->test( [ 'fldA', 'fldB' ], JCValidators::isInt() );
				}
			],
			[
				'chk child, parent', '{"fldA":{"fldB":5}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'fldA', 'fldB' ], JCValidators::isInt() );
					$o->test( [ 'fldA' ], JCValidators::isDictionary() );
				}
			],
			[
				'fldA:string', '{"fldA":5}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isString() );
				}, 1,
			],
			[
				'dupl caps', '{"fldA":5, "flda":2}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
				}, 1,
			],
			[
				'fld to array', '{"fldA":{"a":1,"b":2}}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isDictionary(), static function ( JCValue $v ) {
						$v->setValue( (array)$v->getValue() );
					} );
				},
			],
			[
				'sort1',
				'{"unknown":1, "checked":2}',
				[
					'{"unknown":1, "checked":2, "default":0}',
					'{"default":0, "checked":2, "unknown":1}'
				],
				[ '{"unknown":1, "checked":2}', '{"checked":2, "unknown":1}' ],
				static function ( JCObjContent $o ) {
					$o->testOptional( 'default', 0, JCValidators::isInt() );
					$o->test( 'checked', JCValidators::isInt() );
				},
			],
			[
				'sort2',
				'{"f":[{"unknown":1, "checked":2}]}',
				[
					'{"f":[{"unknown":1, "checked":2, "default":0}]}',
					'{"f":[{"default":0, "checked":2, "unknown":1}]}'
				],
				[ '{"f":[{"unknown":1, "checked":2}]}', '{"f":[{"checked":2, "unknown":1}]}' ],
				static function ( JCObjContent $o ) {
					$o->testOptional( [ 'f', 0, 'default' ], 0, JCValidators::isInt() );
					$o->test( [ 'f', 0, 'checked' ], JCValidators::isInt() );
				},
			],
			[
				'sort3',
				'{"a":1, "b":2}', [ '{"a":1, "b":2}', '{"b":2, "a":1}' ], true,
				static function ( JCObjContent $o ) {
					$o->test( 'a', JCValidators::isInt() );
					$o->test( 'b', JCValidators::isInt() );
					$o->test( 'a', null );
				},
			],
			[
				'missing no dflt f', '{"y":5}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'f', static function ( JCValue $v ) {
						Assert::assertTrue( $v->isMissing() );
					} );
				},
			],
			[
				'missing no dflt f[0]', '{"f":[]}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'f', 0 ], static function ( JCValue $v ) {
						Assert::assertTrue( $v->isMissing() );
					} );
				},
			],
			[
				'missing no dflt f[1]', '{"f":[{"x":1}]}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'f', 1 ], static function ( JCValue $v ) {
						Assert::assertTrue( $v->isMissing() );
					} );
				},
			],
			[
				'missing no dflt f[0]/y', '{"f":[{"x":1}]}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( [ 'f', 0, 'y' ], static function ( JCValue $v ) {
						Assert::assertTrue( $v->isMissing() );
					} );
				},
			],
			[
				'fail val1', '{"f":1}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'f', JCValidators::isBool() );
				}, 1,
			],
			[
				'fail val2', '{"f":1}', true, true,
				static function ( JCObjContent $o ) {
					$o->test( 'f', JCValidators::isInt(), JCValidators::isBool() );
				}, 1,
			],
			[
				'fail opt val1', '{"f":1}', true, true,
				static function ( JCObjContent $o ) {
					$o->testOptional( 'f', true, JCValidators::isBool() );
				}, 1,
			],
			[
				'fail opt val2', '{"f":1}', true, true,
				static function ( JCObjContent $o ) {
					$o->testOptional( 'f', 0, JCValidators::isInt(), JCValidators::isBool() );
				}, 1,
			],

			// $message, $initial, $expectedWithDflts, $expectedNoDflts, $validators, $errors = null
		] );
	}

	/**
	 * This provider helps with running test(s) before the rest of the ones in provideValidation()
	 * Helps with debugging - copy a test from above here and it will run first
	 */
	public static function provideValidationFirst() {
		return [];
	}

	public function assertJsonEquals( string $expected, $actual, string $msg ) {
		$expected = json_decode( $expected ); // normalize string json
		$this->assertSame( json_encode( $expected ), json_encode( $actual ), $msg );
	}
}
