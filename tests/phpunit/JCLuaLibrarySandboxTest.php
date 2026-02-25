<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

/**
 * @group Lua
 * @group LuaSandbox
 * @group Database
 */
class JCLuaLibrarySandboxTest extends JCLuaLibraryTest {
	protected function getEngineName(): string {
		return 'LuaSandbox';
	}
}
