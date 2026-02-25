<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

use MediaWiki\Extension\Scribunto\Tests\Engines\LuaCommon\LuaEngineTestBase;
use PHPUnit\Framework\TestSuite;
use ReflectionClass;

if ( !class_exists( LuaEngineTestBase::class ) ) {
	return;
}

/**
 * @covers \JsonConfig\JCLuaLibrary
 *
 * @license GPL-2.0-or-later
 * @group Database
 */
abstract class JCLuaLibraryTest extends LuaEngineTestBase {

	/** @var string */
	protected static $moduleName = 'JCLuaLibraryTest';

	/**
	 * Override the dynamic suite building from older versions of Scribunto's
	 * LuaEngineTestBase, which tries to execute Lua during --list-tests-xml
	 * and fails for extensions that register custom Lua libraries.
	 * When the new Scribunto (with getEngineName()) is present, use standard
	 * PHPUnit test discovery. With old Scribunto, return an empty suite since
	 * the dynamic suite builder is incompatible with the new class hierarchy.
	 * This can be removed once the Scribunto refactoring has been merged.
	 * @see T358394
	 * @param string $className
	 * @return TestSuite
	 */
	public static function suite( $className = '' ) {
		$rc = new ReflectionClass( LuaEngineTestBase::class );
		if ( $rc->hasMethod( 'getEngineName' ) ) {
			// New Scribunto: use standard PHPUnit discovery
			return new TestSuite( static::class );
		}
		// Old Scribunto: skip, the dynamic suite builder is incompatible
		return new TestSuite();
	}

	protected function getTestModules() {
		return parent::getTestModules() + [
				'JCLuaLibraryTest' => __DIR__ . '/JCLuaLibraryTest.lua',
			];
	}

	protected function setUp(): void {
		$this->overrideConfigValue( 'JsonConfigEnableLuaSupport', true );
		parent::setUp();
	}

}
