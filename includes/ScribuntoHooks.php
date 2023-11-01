<?php

namespace JsonConfig;

use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;

/**
 * Hook handlers for JsonConfig extension.
 * All hooks from the Scribunto extension which is optional to use with this extension.
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @license GPL-2.0-or-later
 */
class ScribuntoHooks implements
	ScribuntoExternalLibrariesHook
{
	/**
	 * @param string $engine
	 * @param string[] &$extraLibraries
	 */
	public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ): void {
		global $wgJsonConfigEnableLuaSupport;
		if ( $wgJsonConfigEnableLuaSupport && $engine == 'lua' ) {
			$extraLibraries['mw.ext.data'] = JCLuaLibrary::class;
		}
	}
}
