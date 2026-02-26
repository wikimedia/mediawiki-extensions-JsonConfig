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

	protected function setUp(): void {
		$this->overrideConfigValue( 'JsonConfigEnableLuaSupport', true );
		parent::setUp();
	}

}
