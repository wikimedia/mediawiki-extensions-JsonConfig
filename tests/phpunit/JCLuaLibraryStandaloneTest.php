<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

use MediaWiki\Extension\Scribunto\Tests\Engines\LuaCommon\LuaEngineTestBase;

if ( !class_exists( LuaEngineTestBase::class ) ) {
	return;
}

/**
 * @group Lua
 * @group LuaStandalone
 * @group Database
 */
class JCLuaLibraryStandaloneTest extends JCLuaLibraryTestBase {
	protected function getEngineName(): string {
		return 'LuaStandalone';
	}
}
