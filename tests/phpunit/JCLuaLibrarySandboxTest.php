<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

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
