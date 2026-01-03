<?php

namespace JsonConfig;

use Article;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Scribunto\EngineFactory;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaModule;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\TextLibrary;
use MediaWiki\Extension\Scribunto\ScribuntoException;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;

class JCTransformer {

	public function __construct(
		private readonly Config $config,
		private readonly ParserFactory $parserFactory,
		private readonly ?EngineFactory $engineFactory,
	) {
	}

	/**
	 * Get data transformed by the specified Lua module, if Scribunto is in use.
	 * This should be called within the context of the store wiki to use centralized modules.
	 *
	 * @param JCTitle $title
	 * @param JCDataContent $content wrapped content object to transformed
	 * @param JCTransform $transform spec for the transform operation
	 * @return Status<JCContentWrapper>
	 */
	public function execute( JCTitle $title, JCDataContent $content, JCTransform $transform ): Status {
		if ( !$this->config->get( 'JsonConfigEnableLuaSupport' )
			|| !$this->config->get( 'JsonConfigTransformsEnabled' )
			|| !$this->engineFactory
		) {
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
		$parser = $this->parserFactory->getInstance();
		$parser->startExternalParse( $rawtitle, $options, Parser::OT_HTML );

		JCSingleton::recordJsonLink( $parser->getOutput(), $article->getTitle()->getTitleValue() );

		$module = $transform->getModule();
		$moduleTitle = Title::makeTitleSafe( NS_MODULE, $module );
		if ( !$moduleTitle ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-module-name', $module );
		}

		$engine = $this->engineFactory->getEngineForParser( $parser );
		if ( !( $engine instanceof LuaEngine ) ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-module-engine', $module );
		}

		$mod = $engine->fetchModuleFromParser( $moduleTitle );
		if ( !( $mod instanceof LuaModule ) ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-module', $module );
		}

		$function = $transform->getFunction();
		$func = $engine->executeModule( $mod->getInitChunk(), $function, null );
		if ( !$func || !$engine->getInterpreter()->isLuaFunction( $func ) ) {
			return Status::newFatal( 'jsonconfig-transform-invalid-function', $module, $function );
		}

		// Args may contain a mix of positional and named parameters
		$args = [];
		foreach ( $transform->getArgs() as $key => $val ) {
			if ( is_string( $key ) ) {
				$args[ $key ] = $val;
			} else {
				$args[ $key + 1 ] = $val;
			}
		}
		try {
			$transformedData = $engine->getInterpreter()->callFunction( $func, $data, $args )[ 0 ] ?? null;
			if ( !is_array( $transformedData ) ) {
				// Required to return a table, which should result in an array on our end
				return Status::newFatal( 'jsonconfig-transform-failed', $module, $function );
			}
			if ( $content instanceof JCTabularContent ) {
				// Support null values in data arrays, specific to JCTabularContent.
				$fields = $transformedData['schema']['fields'] ?? [];
				$data = $transformedData['data'] ?? [];
				if ( is_array( $fields ) && is_array( $data ) ) {
					$cols = array_keys( $fields );
					$newdata = [];
					foreach ( $data as $j => &$row ) {
						$newrow = [];
						if ( is_array( $row ) ) {
							foreach ( $cols as $i ) {
								$newrow[$i] = $row[$i] ?? null;
							}
						}
						$newdata[$j] = $newrow;
					}
					$transformedData['data'] = $newdata;
				}
			}
			$arr = TextLibrary::reindexArrays( $transformedData, true );
			$json = json_encode( $arr );
			$status = JCUtils::hydrate( $title, $json, true );
		} catch ( ScribuntoException $e ) {
			$status = Status::newFatal( 'jsonconfig-transform-error',
				$module, $function, get_class( $e ), $e->getMessage() );
		}

		if ( $status->isOK() ) {
			$content = $status->getValue();
			if ( $content->isValid() ) {
				$status = Status::newGood( JCContentWrapper::newFromContent(
					$title, $content, $parser->getOutput() ) );
			} else {
				$status = $content->getStatus();
			}
		}

		return $status;
	}
}
