<?php
namespace JsonConfig\Tests;

use JsonConfig\JCObjContent;
use JsonConfig\JCValue;
use JsonConfig\JCValidators;
use MediaWikiTestCase;

/**
 * @package JsonConfigTests
 * @group JsonConfig
*/
class JCObjContentTest extends MediaWikiTestCase {

	/** @dataProvider provideBasic */
	public function testBasic( $text, $isValid ) {
		foreach ( array( false, true ) as $thorough ) {
			$msg = "'$text'" . ( $thorough ? '::thorough' : '::quick' );
			$c = new ObjContent( $text, null, $thorough );
			if ( $isValid === null ) {
				$this->assertFalse( $c->isValidJson(), $msg . '-invalid-json' );
			} else {
				$this->assertTrue( $c->isValidJson(), $msg . '-valid-json' );
				$this->assertEquals( $isValid, $c->isValid(), $msg . '-isValid' );
			}
		}
	}

	public function provideBasic() {
		return array(
			array( '', null ),
			array( 'null', null ),
			array( 'bad', null ),
			array( '[]', false ),
			array( 'true', false ),
			array( 'false', false ),
			array( '""', false ),
			array( '"abc"', false ),
			array( '{}', true ),
		);
	}

	/**
	 * @-dataProvider provideValidationFirst
	 * @dataProvider provideValidation
	 */
	public function testValidation( $message, $initial, $expectedWithDflts, $expectedNoDflts, $validators, $errors = null ) {
		if ( $expectedWithDflts === true ) {
			$expectedWithDflts = $initial;
		}
		if ( $expectedNoDflts === true ) {
			$expectedNoDflts = $expectedWithDflts;
		}
		foreach ( array( false, true ) as $th ) {
			$c = new ObjContent( $initial, $validators, $th );
			$msg = $message . '::' . ( $th ? 'thorough' : 'quick' ) . '::';
			if ( !$errors ) {
				$this->assertTrue( $c->isValid(), $msg . 'MUST-BE-VALID' );
			} else {
				$this->assertFalse( $c->isValid(), $msg . 'MUST-BE-INVALID' );
				$errCount = is_int( $errors ) ? $errors : count( $errors );
				$this->assertCount( $errCount, $c->getStatus()->getErrorsArray(), $msg . 'ERROR-COUNT' );
			}
			$expected = is_array( $expectedWithDflts ) ? $expectedWithDflts[(int)$th] : $expectedWithDflts;
			$this->assertJsonEquals( $expected, $c->getDataWithDefaults(), $msg . 'WITH-DEFAULTS' );

			if ( $expectedNoDflts ) {
				$expected = is_array( $expectedNoDflts ) ? $expectedNoDflts[(int)$th] : $expectedNoDflts;
			}
			$this->assertJsonEquals( $expected, $c->getData(), $msg . 'NO-DEFAULTS' );
		}
	}

	public function provideValidation() {
		$self = $this;
		return array_merge( $this->provideValidationFirst(), array(

			//
			// $message, $initial, $expectedWithDflts, $expectedNoDflts, $validators, $errors = null
			//

			array(
				'fldA', '{"fldA":5}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
				}
			),
			array(
				'fldA caps', '{"flda":5}', '{"fldA":5}', true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
				}
			),
			array(
				'int field in obj', '{"1":false}', true, true,
				function ( JCObjContent $o ) {
					$o->test( '1', JCValidators::isBool() );
				}
			),
			array(
				'fldA twice', '{"fldA":5}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
					$o->test( 'fldA', JCValidators::isInt() );
				}
			),
			array(
				'fldA twice caps', '{"flda":5}', '{"fldA":5}', true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
					$o->test( 'fldA', JCValidators::isInt() );
				}
			),
			array(
				'fld/fldA=5', '{"fld":{"fldA":5}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fld', 'fldA' ), JCValidators::isInt() );
				}
			),
			array(
				'twice fld/fldA=5', '{"fld":{"fldA":5}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fld', 'fldA' ), JCValidators::isInt() );
					$o->test( array( 'fld', 'fldA' ), JCValidators::isInt() );
				}
			),
			array(
				'fld[0]=5', '{"fld":[5]}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fld', 0 ), JCValidators::isInt() );
				}
			),
			array(
				'twice fld[0]=5', '{"fld":[5]}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fld', 0 ), JCValidators::isInt() );
					$o->test( array( 'fld', 0 ), JCValidators::isInt() );
				}
			),
			array(
				'fld/fldA/fldB=5', '{"fld":{"fldA":{"fldB":5}}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fld', 'fldA', 'fldB' ), JCValidators::isInt() );
				}
			),
			array(
				'opt fldA', '{}', '{"fldA":5}', '{}',
				function ( JCObjContent $o ) {
					$o->testOptional( 'fldA', 5 );
				}
			),
			array(
				'opt fld/fldA=5', '{}', '{"fld":{"fldA":5}}', '{"fld":{}}',
				function ( JCObjContent $o ) {
					$o->testOptional( array( 'fld', 'fldA' ), 5 );
				}
			),
			array(
				'opt fld[0]=5', '{}', '{"fld":[5]}', true,
				function ( JCObjContent $o ) {
					$o->testOptional( array( 'fld', 0 ), 5 );
				}
			),
			array(
				'opt fld/fldA/fldB=5', '{}', '{"fld":{"fldA":{"fldB":5}}}', '{"fld":{"fldA":{}}}',
				function ( JCObjContent $o ) {
					$o->testOptional( array( 'fld', 'fldA', 'fldB' ), 5 );
				}
			),
			array(
				'del missing fldA', '{}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::deleteField() );
				}
			),
			array(
				'del sub-missing fldA/fldB', '{}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fldA', 'fldB' ), JCValidators::deleteField() );
				}
			),
			array(
				'del sub-missing fldA[0]', '{}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fldA', 0 ), JCValidators::deleteField() );
				}
			),
			array(
				'del fldA', '{"fldA":5}', '{}', true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::deleteField() );
				}
			),
			array(
				'del fldA/fldB', '{"fldA":{"fldB":5}}', '{"fldA":{}}', true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fldA', 'fldB' ), JCValidators::deleteField() );
				}
			),
			array(
				'fldA->fldB', '{"fldA":5}', '{"fldB":5}', true,
				function ( JCObjContent $o ) use ( $self ) {
					$o->test( 'fldA',
						function( JCValue $v, array $path, JCObjContent $cn ) use ( $self ) {
							$new = clone( $v );
							$new->status( JCValue::CHECKED );
							$cn->getValidationData()->setField( 'fldB', $new );
							$v->status( JCValue::MISSING ); // delete this field
							return true;
						} );
				}
			),
			array(
				'fldA/fldB->fldB', '{"fldA":{"fldB":5}}', '{"fldB":5}', true,
				function ( JCObjContent $o ) use ( $self ) {
					$o->test( array( 'fldA', 'fldB' ),
						function( JCValue $v, array $path, JCObjContent $cn ) use ( $self ) {
							$new = clone( $v );
							$new->status( JCValue::CHECKED );
							$cn->getValidationData()->setField( 'fldB', $new );
							$v->status( JCValue::MISSING ); // delete this field
							return true;
						} );
					$o->test( 'fldA', JCValidators::deleteField() );
				}
			),
			array(
				'fldA,fldB', '{"fldA":5,"fldB":true}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
					$o->test( 'fldB', JCValidators::isBool() );
				}
			),
			array(
				'fldX/fldA,fldX/fldB', '{"fldX":{"fldA":5,"fldB":true}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fldX', 'fldA' ), JCValidators::isInt() );
					$o->test( array( 'fldX', 'fldB' ), JCValidators::isBool() );
				}
			),
			array(
				'fldA[0],opt fldA[1]', '{"fldA":[5]}', '{"fldA":[5,true]}', true,
				function ( JCObjContent $o ) {
					$o->testOptional( array( 'fldA', 1 ), true, JCValidators::isBool() );
					$o->test( array( 'fldA', 0 ), JCValidators::isInt() );
				}
			),
			array(
				'chk parent, child', '{"fldA":{"fldB":5}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fldA' ), JCValidators::isDictionary() );
					$o->test( array( 'fldA', 'fldB' ), JCValidators::isInt() );
				}
			),
			array(
				'chk child, parent', '{"fldA":{"fldB":5}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( array( 'fldA', 'fldB' ), JCValidators::isInt() );
					$o->test( array( 'fldA' ), JCValidators::isDictionary() );
				}
			),
			array(
				'fldA:string', '{"fldA":5}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isString() );
				}, 1,
			),
			array(
				'dupl caps', '{"fldA":5, "flda":2}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isInt() );
				}, 1,
			),
			array(
				'fld to array', '{"fldA":{"a":1,"b":2}}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'fldA', JCValidators::isDictionary(), function( JCValue $v ) {
						$v->setValue( (array)$v->getValue() );
					} );
				},
			),
			array(
				'sort1',
				'{"unknown":1, "checked":2}',
				array( '{"unknown":1, "checked":2, "default":0}', '{"default":0, "checked":2, "unknown":1}' ),
				array( '{"unknown":1, "checked":2}', '{"checked":2, "unknown":1}' ),
				function ( JCObjContent $o ) {
					$o->testOptional( 'default', 0, JCValidators::isInt() );
					$o->test( 'checked', JCValidators::isInt() );
				},
			),
			array(
				'sort2',
				'{"f":[{"unknown":1, "checked":2}]}',
				array( '{"f":[{"unknown":1, "checked":2, "default":0}]}', '{"f":[{"default":0, "checked":2, "unknown":1}]}' ),
				array( '{"f":[{"unknown":1, "checked":2}]}', '{"f":[{"checked":2, "unknown":1}]}' ),
				function ( JCObjContent $o ) {
					$o->testOptional( array( 'f', 0, 'default' ), 0, JCValidators::isInt() );
					$o->test( array( 'f', 0, 'checked' ), JCValidators::isInt() );
				},
			),
			array(
				'sort3',
				'{"a":1, "b":2}', array( '{"a":1, "b":2}', '{"b":2, "a":1}' ), true,
				function ( JCObjContent $o ) {
					$o->test( 'a', JCValidators::isInt() );
					$o->test( 'b', JCValidators::isInt() );
					$o->test( 'a', null );
				},
			),
			array(
				'missing no dflt f', '{"y":5}', true, true,
				function ( JCObjContent $o ) use ( $self ) {
					$o->test( 'f', function ( JCValue $v ) use ( $self ) {
						$self->assertEquals( true, $v->isMissing() );
					} );
				},
			),
			array(
				'missing no dflt f[0]', '{"f":[]}', true, true,
				function ( JCObjContent $o ) use ( $self ) {
					$o->test( array( 'f', 0 ), function ( JCValue $v ) use ( $self ) {
						$self->assertEquals( true, $v->isMissing() );
					} );
				},
			),
			array(
				'missing no dflt f[1]', '{"f":[{"x":1}]}', true, true,
				function ( JCObjContent $o ) use ( $self ) {
					$o->test( array( 'f', 1 ), function ( JCValue $v ) use ( $self ) {
						$self->assertEquals( true, $v->isMissing() );
					} );
				},
			),
			array(
				'missing no dflt f[0]/y', '{"f":[{"x":1}]}', true, true,
				function ( JCObjContent $o ) use ( $self ) {
					$o->test( array( 'f', 0, 'y' ), function ( JCValue $v ) use ( $self ) {
						$self->assertEquals( true, $v->isMissing() );
					} );
				},
			),
			array(
				'fail val1', '{"f":1}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'f', JCValidators::isBool() );
				}, 1,
			),
			array(
				'fail val2', '{"f":1}', true, true,
				function ( JCObjContent $o ) {
					$o->test( 'f', JCValidators::isInt(), JCValidators::isBool() );
				}, 1,
			),
			array(
				'fail opt val1', '{"f":1}', true, true,
				function ( JCObjContent $o ) {
					$o->testOptional( 'f', true, JCValidators::isBool() );
				}, 1,
			),
			array(
				'fail opt val2', '{"f":1}', true, true,
				function ( JCObjContent $o ) {
					$o->testOptional( 'f', 0, JCValidators::isInt(), JCValidators::isBool() );
				}, 1,
			),

			//
			// $message, $initial, $expectedWithDflts, $expectedNoDflts, $validators, $errors = null
		    //
		) );
	}

	/**
	 * This provider helps with running test(s) before the rest of the ones in provideValidation()
	 * Helps with debugging - copy a test from above here and it will run first
	 */
	public function provideValidationFirst() {
		$self = $this;
		return array(
		);
	}

	public function assertJsonEquals( $expected, $actual, $msg ) {
		$expected = json_decode( $expected ); // normalize string json
		$this->assertEquals( json_encode( $expected ), json_encode( $actual ), $msg );
	}
}

class ObjContent extends JCObjContent {
	private $validators;
	public function __construct( $data, $validators, $thorough, $isRootArray = false ) {
		$this->validators = $validators;
		$this->isRootArray = $isRootArray;
		$text = is_string( $data ) ? $data : json_encode( $data );
		parent::__construct( $text, 'JsonConfig.Test', $thorough );
	}

	public function validateContent() {
		if ( $this->validators ) {
			call_user_func( $this->validators, $this );
		}
	}
}
