<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

use MediaWiki\Extension\Scribunto\Tests\Engines\LuaCommon\LuaEngineTestBase;

/**
 * @covers \JsonConfig\JCLuaLibrary
 *
 * @license GPL-2.0-or-later
 * @group Database
 */
abstract class JCLuaLibraryTestBase extends LuaEngineTestBase {

	/** @var string */
	protected static $moduleName = 'JCLuaLibraryTest';

	protected function getTestModules() {
		return parent::getTestModules() + [
				'JCLuaLibraryTest' => __DIR__ . '/JCLuaLibraryTest.lua',
			];
	}

	public static function provideLuaData(): array {
		// provideLuaData() is static and runs before setUp(), so
		// overrideConfigValue() in setUp() is too late: the engine created
		// here would not have mw.ext.data registered. Enable the config via
		// the global (read dynamically by GlobalVarConfig) and restore it.
		global $wgJsonConfigEnableLuaSupport;
		$original = $wgJsonConfigEnableLuaSupport ?? null;
		$wgJsonConfigEnableLuaSupport = true;
		try {
			return parent::provideLuaData();
		} finally {
			$wgJsonConfigEnableLuaSupport = $original;
		}
	}

	protected function setUp(): void {
		$this->overrideConfigValue( 'JsonConfigEnableLuaSupport', true );
		parent::setUp();
	}

}
