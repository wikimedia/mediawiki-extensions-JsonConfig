<?php

namespace JsonConfig;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;

/**
 * Hook handlers for JsonConfig extension.
 * All hooks from the Scribunto extension which is optional to use with this extension.
 *
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @license GPL-2.0-or-later
 */
class ScribuntoHooks implements
	ScribuntoExternalLibrariesHook
{
	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * @param string $engine
	 * @param string[] &$extraLibraries
	 */
	public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ): void {
		$enableLuaSupport = $this->config->get( 'JsonConfigEnableLuaSupport' );
		if ( $enableLuaSupport && $engine === 'lua' ) {
			$extraLibraries['mw.ext.data'] = JCLuaLibrary::class;
		}
	}
}
