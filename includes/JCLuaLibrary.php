<?php

namespace JsonConfig;

use MalformedTitleException;
use Scribunto_LuaError;
use Scribunto_LuaLibraryBase;
use Title;
use TitleValue;

class JCLuaLibrary extends Scribunto_LuaLibraryBase {

	/**
	 * @param string $engine
	 * @param string[] $extraLibraries
	 *
	 * @return bool
	 */
	public static function onScribuntoExternalLibraries( $engine, array &$extraLibraries ) {
		global $wgJsonConfigEnableLuaSupport;
		if ( $wgJsonConfigEnableLuaSupport && $engine == 'lua' ) {
			$extraLibraries['mw.ext.data'] = 'JsonConfig\JCLuaLibrary';
		}

		return true;
	}

	public function register() {
		$functions = [ 'get' => [ $this, 'get' ] ];
		$moduleFileName = __DIR__ . DIRECTORY_SEPARATOR . 'JCLuaLibrary.lua';
		return $this->getEngine()->registerInterface( $moduleFileName, $functions, array() );
	}

	/**
	 * Returns data page as a data table
	 * @param string $titleStr name of the page in the Data namespace
	 * @return false[]|mixed[]
	 * @throws Scribunto_LuaError
	 */
	public function get( $titleStr ) {
		$this->checkType( 'get', 1, $titleStr, 'string' );
		$jct = JCSingleton::parseTitle( $titleStr, NS_DATA );
		if ( !$jct ) {
			throw new Scribunto_LuaError( 'bad argument #1 to "get" (not a valid title)' );
		}

		$content = JCSingleton::getContentFromLocalCache( $jct );
		if ( $content === null ) {
			$this->incrementExpensiveFunctionCount();
			$content = JCSingleton::getContent( $jct );
		}

		return [ $content ? $content->getData() : false ];
	}
}
