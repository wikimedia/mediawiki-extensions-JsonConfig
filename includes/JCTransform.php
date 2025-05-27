<?php

namespace JsonConfig;

use Article;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaModule;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\TextLibrary;
use MediaWiki\Extension\Scribunto\Scribunto;
use MediaWiki\Extension\Scribunto\ScribuntoException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use stdClass;

class JCTransform {
	/**
	 * @var string Lua module name to load
	 */
	private string $module;

	/**
	 * @var string function name to run
	 */
	private string $function;

	/**
	 * @var array array of JSON-compatible arguments to pass to function
	 */
	private array $args;

	/**
	 * @param string $module Lua module name
	 * @param string $function function name
	 * @param array $args optional array to pass JSON-compatible arguments
	 */
	public function __construct( string $module, string $function, array $args = [] ) {
		$this->module = $module;
		$this->function = $function;
		$this->args = $args;
	}

	public function getModule(): string {
		return $this->module;
	}

	public function getFunction(): string {
		return $this->function;
	}

	public function getArgs(): array {
		return $this->args;
	}

	/**
	 * Re-hydrate a JSON object describing a transform into a JCTransform.
	 */
	public static function newFromJson( stdClass $object ): self {
		return new self(
			strval( $object->module ?? '' ),
			strval( $object->function ?? '' ),
			(array)( $object->args ?? [] )
		);
	}

	/**
	 * Create a JSON-compatible object describing the transform.
	 */
	public function toJson(): stdClass {
		return (object)[
			'module' => $this->module,
			'function' => $this->function,
			'args' => $this->args
		];
	}

	/**
	 * Get data transformed by the specified Lua module, if Scribunto is in use.
	 * This should be called within the context of the store wiki to use centralized modules.
	 *
	 * @param JCTitle $title
	 * @param JCDataContent $content wrapped content object to transformed
	 * @return Status<JCContentWrapper>
	 */
	public function execute( JCTitle $title, JCDataContent $content ) {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		if ( !$config->get( 'JsonConfigEnableLuaSupport' )
			|| !$config->get( 'JsonConfigTransformsEnabled' ) ) {
			return Status::newFatal( 'jsonconfig-transform-disabled' );
		}

		$rawtitle = Title::makeTitle( $title->getNamespace(), $title->getDbKey() );
		$context = RequestContext::getMain();
		$article = new Article( $rawtitle );
		$article->setContext( $context );

		$data = $content->getData();
		JCLuaLibrary::reindexTabularData( $data );
		$data = JCLuaLibrary::objectToArray( $data );

		$options = ParserOptions::newFromContext( $context );
		$parser = $services->getParserFactory()->getInstance();
		$parser->startExternalParse( $rawtitle, $options, Parser::OT_HTML );

		JCSingleton::recordJsonLink( $parser->getOutput(), $article->getTitle()->getTitleValue() );

		$moduleTitle = Title::makeTitleSafe( NS_MODULE, $this->module );
		if ( !$moduleTitle ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-module-name', $this->module );
		}

		$engine = Scribunto::getParserEngine( $parser );
		if ( !( $engine instanceof LuaEngine ) ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-module-engine', $this->module );
		}

		$module = $engine->fetchModuleFromParser( $moduleTitle );
		if ( !( $module instanceof LuaModule ) ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-module', $this->module );
		}
		$func = $engine->executeModule( $module->getInitChunk(), $this->function, null );
		if ( !$func || !$engine->getInterpreter()->isLuaFunction( $func ) ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-function', $this->module, $this->function );
		}

		// Args may contain a mix of positional and named parameters
		$args = [];
		foreach ( $this->args as $key => $val ) {
			if ( is_string( $key ) ) {
				$args[ $key ] = $val;
			} else {
				$args[ $key + 1 ] = $val;
			}
		}
		try {
			$transformedData = $engine->getInterpreter()->callFunction( $func, $data, $args )[ 0 ] ?? null;
			if ( !$transformedData ) {
				return Status::newFatal( 'jsonconfig-transform-failed', $this->module, $this->function );
			}
			$arr = TextLibrary::reindexArrays( $transformedData, true );
			$json = json_encode( $arr );
			$status = JCUtils::hydrate( $title, $json );
		} catch ( ScribuntoException $e ) {
			$status = Status::newFatal( 'jsonconfig-transform-error',
				$this->module, $this->function, get_class( $e ), $e->getMessage() );
		}

		if ( $status->isGood() ) {
			$status = Status::newGood( JCContentWrapper::newFromContent(
				$title, $status->getValue(), $parser->getOutput() ) );
		}

		return $status;
	}
}
