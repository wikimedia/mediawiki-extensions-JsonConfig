<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

use MediaWiki\Extension\Scribunto\Tests\Engines\LuaCommon\LuaEngineTestBase;

if ( !class_exists( LuaEngineTestBase::class ) ) {
	return;
}

/**
 * @group Lua
 * @group LuaSandbox
 * @group Database
 */
class JCLuaLibrarySandboxTest extends JCLuaLibraryTestBase {
	protected function getEngineName(): string {
		return 'LuaSandbox';
	}
}
