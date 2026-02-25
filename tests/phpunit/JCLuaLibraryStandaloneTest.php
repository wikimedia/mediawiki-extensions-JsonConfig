<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

/**
 * @group Lua
 * @group LuaStandalone
 * @group Database
 */
class JCLuaLibraryStandaloneTest extends JCLuaLibraryTest {
	protected function getEngineName(): string {
		return 'LuaStandalone';
	}
}
