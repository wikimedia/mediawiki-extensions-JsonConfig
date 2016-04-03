<?php
namespace JsonConfig;

use ContentHandler;
use Exception;
use stdClass;
use TitleValue;
use Title;

/**
 * Static utility methods and configuration page hook handlers for JsonConfig extension.
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @author Yuri Astrakhan
 * @copyright © 2013 Yuri Astrakhan
 * @license GNU General Public Licence 2.0 or later
 */
class JCSingleton {
	/**
	 * @var array describes how a title should be handled by JsonConfig extension.
	 * The structure is an array of array of ...:
	 * { int_namespace => { name => { allows-sub-namespaces => configuration_array } } }
	 */
	static $titleMap = array();

	/**
	 * @var string[]|false[] containing all the namespaces handled by JsonConfig
	 * Maps namespace id (int) => namespace name (string).
	 * If false, presumes the namespace has been registered by core or another extension
	 */
	static $namespaces = array();

	/**
	 * Initializes singleton state by parsing $wgJsonConfig* values
	 * @throws \Exception
	 */
	private static function init() {
		static $isInitialized = false;
		if ( $isInitialized ) {
			return;
		}
		$isInitialized = true;
		global $wgNamespaceContentModels, $wgContentHandlers, $wgJsonConfigs, $wgJsonConfigModels;
		list( self::$titleMap, self::$namespaces ) = self::parseConfiguration(
			$wgNamespaceContentModels, $wgContentHandlers, $wgJsonConfigs, $wgJsonConfigModels );
	}

	/**
	 * @param array $namespaceContentModels $wgNamespaceContentModels
	 * @param array $contentHandlers $wgContentHandlers
	 * @param array $configs $wgJsonConfigs
	 * @param array $models $wgJsonConfigModels
	 * @param bool $warn if true, calls wfLogWarning() for all errors
	 * @return array [ $titleMap, $namespaces ]
	 */
	public static function parseConfiguration( array $namespaceContentModels, array $contentHandlers,
											   array $configs, array $models, $warn = true ) {
		$defaultModelId = 'JsonConfig';
		$warnFunc = $warn ? 'wfLogWarning' : function() {};

		$namespaces = array();
		$titleMap = array();
		foreach ( $configs as $confId => &$conf ) {
			if ( !is_string( $confId ) ) {
				$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId'], the key must be a string" );
				continue;
			}
			if ( null === self::getConfObject( $warnFunc, $conf, $confId ) ) {
				continue; // warned inside the function
			}

			$modelId = property_exists( $conf, 'model' ) ? ( $conf->model ? : $defaultModelId ) : $confId;
			if ( !array_key_exists( $modelId, $models ) ) {
				if ( $modelId === $defaultModelId ) {
					$models[$defaultModelId] = null;
				} else {
					$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
							   "Model '$modelId' is not defined in \$wgJsonConfigModels" );
					continue;
				}
			}
			if ( array_key_exists( $modelId, $contentHandlers ) ) {
				$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: Model '$modelId' is " .
						   "already registered in \$contentHandlers to {$contentHandlers[$modelId]}" );
				continue;
			}
			$conf->model = $modelId;

			$ns = self::getConfVal( $conf, 'namespace', NS_CONFIG );
			if ( !is_int( $ns ) || $ns % 2 !== 0 ) {
				$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
						   "Namespace $ns should be an even number" );
				continue;
			}
			// Even though we might be able to override default content model for namespace, lets keep things clean
			if ( array_key_exists( $ns, $namespaceContentModels ) ) {
				$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: Namespace $ns is already " .
						   "set to handle model '$namespaceContentModels[$ns]'" );
				continue;
			}

			// nsName & nsTalk are handled later
			self::getConfVal( $conf, 'pattern', '' );
			self::getConfVal( $conf, 'cacheExp', 24 * 60 * 60 );
			self::getConfVal( $conf, 'cacheKey', '' );
			self::getConfVal( $conf, 'flaggedRevs', false );
			$islocal = self::getConfVal( $conf, 'isLocal', true );

			// Decide if matching configs should be stored on this wiki
			$storeHere = $islocal || property_exists( $conf, 'store' );
			if ( !$storeHere ) {
				$conf->store = false; // 'store' does not exist, use it as a flag to indicate remote storage
				if ( null === ( $remote = self::getConfObject( $warnFunc, $conf, 'remote', $confId, 'url' ) ) ) {
					continue; // warned inside the function
				}
				if ( self::getConfVal( $remote, 'url', '' ) === '' ) {
					$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']['remote']['url']: " .
							   "API URL is not set, and this config is not being stored locally" );
					continue;
				}
				self::getConfVal( $remote, 'username', '' );
				self::getConfVal( $remote, 'password', '' );
			} else {
				if ( property_exists( $conf, 'remote' ) ) {
					// non-fatal -- simply ignore the 'remote' setting
					$warnFunc( "JsonConfig: In \$wgJsonConfigs['$confId']['remote'] is set for the config that will be stored on this wiki. 'remote' parameter will be ignored." );
				}
				$conf->remote = null;
				if ( null === ( $store = self::getConfObject( $warnFunc, $conf, 'store', $confId ) ) ) {
					continue; // warned inside the function
				}
				self::getConfVal( $store, 'cacheNewValue', true );
				self::getConfVal( $store, 'notifyUrl', '' );
				self::getConfVal( $store, 'notifyUsername', '' );
				self::getConfVal( $store, 'notifyPassword', '' );
			}

			// Too lazy to write proper error messages for all parameters.
			if ( ( isset( $conf->nsTalk ) && !is_string( $conf->nsTalk ) ) || !is_string( $conf->pattern ) ||
			     !is_bool( $islocal ) || !is_int( $conf->cacheExp ) ||
			     !is_string( $conf->cacheKey ) || !is_bool( $conf->flaggedRevs )
			) {
				$warnFunc( "JsonConfig: Invalid type of one of the parameters in \$wgJsonConfigs['$confId'], please check documentation" );
				continue;
			}
			if ( isset( $remote ) ) {
				if ( !is_string( $remote->url ) || !is_string( $remote->username ) ||
				     !is_string( $remote->password )
				) {
					$warnFunc( "JsonConfig: Invalid type of one of the parameters in \$wgJsonConfigs['$confId']['remote'], please check documentation" );
					continue;
				}
			}
			if ( isset( $store ) ) {
				if ( !is_bool( $store->cacheNewValue ) || !is_string( $store->notifyUrl ) ||
				     !is_string( $store->notifyUsername ) || !is_string( $store->notifyPassword )
				) {
					$warnFunc( "JsonConfig: Invalid type of one of the parameters in \$wgJsonConfigs['$confId']['store'], please check documentation" );
					continue;
				}
			}
			if ( $storeHere ) {
				// If nsName is given, add it to the list, together with the talk page
				// Otherwise, create a placeholder for it
				if ( property_exists( $conf, 'nsName' ) ) {
					if ( $conf->nsName === false ) {
						// Non JC-specific namespace, don't register it
						if ( !array_key_exists( $ns, $namespaces ) ) {
							$namespaces[$ns] = false;
						}
				    } elseif ( $ns === NS_CONFIG ) {
						$warnFunc( "JsonConfig: Parameter 'nsName' in \$wgJsonConfigs['$confId'] is not " .
								   "supported for namespace == NS_CONFIG ($ns)" );
					} else {
						$nsName = $conf->nsName;
						$nsTalk = isset( $conf->nsTalk ) ? $conf->nsTalk : ( $nsName . '_talk' );
						if ( !is_string( $nsName ) || $nsName === '' ) {
							$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
									   "if given, nsName must be a string" );
							continue;
						} elseif ( array_key_exists( $ns, $namespaces ) &&
						           $namespaces[$ns] !== null
						) {
							if ( $namespaces[$ns] !== $nsName ||
								 $namespaces[$ns + 1] !== $nsTalk
							) {
								$warnFunc( "JsonConfig: \$wgJsonConfigs['$confId'] - " .
										   "nsName has already been set for namespace $ns" );
							}
						} else {
							$namespaces[$ns] = $nsName;
							$namespaces[$ns + 1] =
								isset( $conf->nsTalk ) ? $conf->nsTalk : ( $nsName . '_talk' );
						}
					}
				} elseif ( !array_key_exists( $ns, $namespaces ) || $namespaces[$ns] === false ) {
					$namespaces[$ns] = null;
				}
			}

			if ( !array_key_exists( $ns, $titleMap ) ) {
				$titleMap[$ns] = array( $conf );
			} else {
				$titleMap[$ns][] = $conf;
			}
		}

		// Add all undeclared namespaces
		$missingNs = 1;
		foreach ( $namespaces as $ns => $nsName ) {
			if ( $nsName === null ) {
				$nsName = 'Config';
				if ( $ns !== NS_CONFIG ) {
					$nsName .= $missingNs;
					$warnFunc( "JsonConfig: Namespace $ns does not have 'nsName' defined, using '$nsName'" );
					$missingNs += 1;
				}
				$namespaces[$ns] = $nsName;
				$namespaces[$ns + 1] = $nsName . '_talk';
			}
		}

		return [ $titleMap, $namespaces ];
	}

	/**
	 * Helper function to check if configuration has a field set, and if not, set it to default
	 * @param stdClass $conf
	 * @param string $field
	 * @param mixed $default
	 * @return mixed
	 */
	private static function getConfVal( & $conf, $field, $default ) {
		if ( property_exists( $conf, $field ) ) {
			return $conf->$field;
		}
		$conf->$field = $default;
		return $default;
	}

	/**
	 * Helper function to check if configuration has a field set, and if not, set it to default
	 * @param $warnFunc
	 * @param $value
	 * @param string $field
	 * @param string $confId
	 * @param string $treatAsField
	 * @return null|object|stdClass
	 */
	private static function getConfObject( $warnFunc, & $value, $field, $confId = null, $treatAsField = null ) {
		if ( !$confId ) {
			$val = & $value;
		} else {
			if ( !property_exists( $value, $field ) ) {
				$value->$field = null;
			}
			$val = & $value->$field;
		}
		if ( $val === null || $val === true ) {
			$val = new stdClass();
		} elseif ( is_array( $val ) ) {
			$val = (object)$val;
		} elseif ( is_string( $val ) && $treatAsField !== null ) {
			// treating this string value as a sub-field
			$val = (object) array( $treatAsField => $val );
		} elseif ( !is_object( $val ) ) {
			$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs" . ( $confId ? "['$confId']" : "" ) .
					   "['$field'], the value must be either an array or an object" );
			return null;
		}
		return $val;
	}

	/**
	 * Get content object for the given title.
	 * Title may not contain ':' unless it is a sub-namespace separator. Namespace ID does not need to be
	 * defined in the current wiki, as long as it is defined in $wgJsonConfigs.
	 * @param TitleValue $titleValue
	 * @param string $jsonText if given, parses this text instead of what's stored in the database/cache
	 * @return bool|JCContent Returns false if the title is not handled by the settings
	 */
	public static function getContent( TitleValue $titleValue, $jsonText = null ) {
		$conf = self::getMetadata( $titleValue );
		if ( $conf ) {
			if ( is_string( $jsonText ) ) {
				$content = $jsonText;
			} else {
				$store = new JCCache( $titleValue, $conf );
				$content = $store->get();
			}
			if ( is_string( $content ) ) {
				// Convert string to the content object if needed
				$handler = new JCContentHandler( $conf->model );
				return $handler->unserializeContent( $content, null, false );
			} elseif ( $content !== false ) {
				return $content;
			}
		}
		return false;
	}

	/**
	 * Mostly for debugging purposes, this function returns initialized internal JsonConfig settings
	 * @return array
	 */
	public static function getTitleMap() {
		self::init();
		return self::$titleMap;
	}

	public static function getContentClass( $modelId ) {
		global $wgJsonConfigModels;
		$class = null;
		if ( array_key_exists( $modelId, $wgJsonConfigModels ) ) {
			$value = $wgJsonConfigModels[$modelId];
			if ( is_array( $value ) ) {
				if ( !array_key_exists( 'class', $value ) ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigModels['$modelId'] array value, 'class' not found" );
				} else {
					$class = $value['class'];
				}
			} else {
				$class = $value;
			}
		}
		if ( !$class ) {
			$class = __NAMESPACE__ . '\JCContent';
		}
		return $class;
	}

	/**
	 * Returns an array with settings if the $titleValue object is handled by the JsonConfig extension,
	 * false if unrecognized namespace, and null if namespace is handled but not this title
	 * @param TitleValue $titleValue
	 * @return stdClass|false|null
	 */
	public static function getMetadata( $titleValue ) {
		static $lastTitle = null;
		static $lastResult = false;
		if ( !$titleValue ) {
			return false; // It is possible to have a null TitleValue (bug 66555)
		} elseif ( $titleValue === $lastTitle ) {
			return $lastResult;
		}
		$lastTitle = $titleValue;
		$ns = $titleValue->getNamespace();
		/** @var array[] $map array of:  { namespace => [ configs ] } */
		$map = self::getTitleMap();
		if ( array_key_exists( $ns, $map ) ) {
			$text = $titleValue->getText();
			foreach ( $map[$ns] as $conf ) {
				$re = $conf->pattern;
				if ( !$re || preg_match( $re, $text ) ) {
					$lastResult = $conf;
					return $lastResult;
				}
			}
			// We know about the namespace, but there is no specific configuration
			$lastResult = null;
			return $lastResult;
		}
		$lastResult = false;
		return $lastResult;
	}

	/**
	 * Only register NS_CONFIG if running on the MediaWiki instance which houses the JSON configs (i.e. META)
	 * @param array $namespaces
	 */
	public static function onCanonicalNamespaces( array &$namespaces ) {
		self::init();
		foreach ( self::$namespaces as $ns => $name ) {
			if ( $name === false ) { // must be already declared
				if ( !array_key_exists( $ns, $namespaces ) ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs: Namespace $ns " .
					              "has not been declared by core or other extensions" );
				}
			} elseif ( array_key_exists( $ns, $namespaces ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs: Namespace $ns => '$name' " .
					"is already declared as '$namespaces[$ns]'" );
			} else {
				$key = array_search( $name, $namespaces );
				if ( $key !== false ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs: Namespace $ns => '$name' " .
						"has identical name with the namespace #$key" );
				} else {
					$namespaces[$ns] = $name;
				}
			}
		}
	}

	/**
	 * Initialize state
	 * @param Title $title
	 * @param string $modelId
	 * @return bool
	 */
	public static function onContentHandlerDefaultModelFor( $title, &$modelId ) {
		$conf = self::getMetadata( $title->getTitleValue() );
		if ( $conf ) {
			$modelId = $conf->model;
			return false;
		}
		return true;
	}

	/**
	 * Instantiate JCContentHandler if we can handle this modelId
	 * @param string $modelId
	 * @param \ContentHandler $handler
	 * @return bool
	 */
	public static function onContentHandlerForModelID( $modelId, &$handler ) {
		self::init();
		global $wgJsonConfigModels;
		if ( array_key_exists( $modelId, $wgJsonConfigModels ) ) {
			// This is one of our model IDs
			$handler = new JCContentHandler( $modelId );
			return false;
		}
		return true;
	}

	/**
	 * Declares JSON as the code editor language for Config: pages.
	 * This hook only runs if the CodeEditor extension is enabled.
	 * @param Title $title
	 * @param string &$lang Page language.
	 * @return bool
	 */
	static function onCodeEditorGetPageLanguage( $title, &$lang ) {
		$handler = ContentHandler::getForModelID( $title->getContentModel() );
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON || self::getMetadata( $title->getTitleValue() ) ) {
			$lang = 'json';
		}
		return true;
	}

	/**
	 * Validates that the revised contents are valid JSON.
	 * If not valid, rejects edit with error message.
	 * @param \IContextSource $context
	 * @param JCContent $content
	 * @param \Status $status
	 * @param string $summary Edit summary provided for edit.
	 * @param \User $user
	 * @param bool $minoredit
	 * @return bool
	 */
	static function onEditFilterMergedContent( /** @noinspection PhpUnusedParameterInspection */
		$context, $content, $status, $summary, $user, $minoredit ) {
		if ( is_a( $content, 'JsonConfig\JCContent' ) ) {
			$status->merge( $content->getStatus() );
			if ( !$status->isGood() ) {
				$status->ok = false;
			}
		}
		return true;
	}

	/**
	 * Adds CSS for pretty-printing configuration on NS_CONFIG pages.
	 * @param \OutputPage &$out
	 * @param \Skin &$skin
	 * @return bool
	 */
	static function onBeforePageDisplay( /** @noinspection PhpUnusedParameterInspection */ &$out, &$skin ) {
		$title = $out->getTitle();
		$handler = ContentHandler::getForModelID( $title->getContentModel() );
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON ||
			self::getMetadata( $title->getTitleValue() )
		) {
			$out->addModuleStyles( 'ext.jsonConfig' );
		}
		return true;
	}

	public static function onMovePageIsValidMove( Title $oldTitle, Title $newTitle, \Status $status ) {
		$conf = self::getMetadata( $oldTitle->getTitleValue() );
		if ( $conf ) {
			$newConf = self::getMetadata( $newTitle->getTitleValue() );
			if ( !$newConf ) {
				// @todo: is parse() the right func to use here?
				$status->fatal( 'jsonconfig-move-aborted-ns' );
				return false;
			} elseif ( $conf->model !== $newConf->model ) {
				$status->fatal( 'jsonconfig-move-aborted-model', $conf->model, $newConf->model );
				return false;
			}
		}

		return true;
	}

	public static function onAbortMove( /** @noinspection PhpUnusedParameterInspection */ Title $title, Title $newTitle, $wgUser, &$err, $reason ) {
		$status = new \Status();
		self::onMovePageIsValidMove( $title, $newTitle, $status );
		if ( !$status->isOK() ) {
			$err = $status->getHTML();
			return false;
		}

		return true;
	}

	public static function onPageContentSaveComplete( /** @noinspection PhpUnusedParameterInspection */ $article, $user, $content, $summary, $isMinor, $isWatch,
		$section, $flags, $revision, $status, $baseRevId ) {
		return self::onArticleChangeComplete( $article, $content );
	}

	public static function onArticleDeleteComplete( /** @noinspection PhpUnusedParameterInspection */ $article, &$user, $reason, $id, $content, $logEntry ) {
		return self::onArticleChangeComplete( $article );
	}

	public static function onArticleUndelete( /** @noinspection PhpUnusedParameterInspection */ $title, $created, $comment, $oldPageId ) {
		return self::onArticleChangeComplete( $title );
	}

	public static function onTitleMoveComplete( /** @noinspection PhpUnusedParameterInspection */ $title, $newTitle, $wgUser, $pageid, $redirid, $reason ) {
		return self::onArticleChangeComplete( $title ) ||
		       self::onArticleChangeComplete( $newTitle );
	}

	/**
	 * Prohibit creation of the pages that are part of our namespaces but have not been explicitly allowed
	 * Bad capitalization is due to "userCan" hook name
	 * @param Title $title
	 * @param $user
	 * @param string $action
	 * @param null $result
	 * @return bool
	 */
	public static function onuserCan( /** @noinspection PhpUnusedParameterInspection */ &$title, &$user, $action, &$result = null ) {
		if ( $action === 'create' && self::getMetadata( $title->getTitleValue() ) === null ) {
			// prohibit creation of the pages for the namespace that we handle,
			// if the title is not matching declared rules
			$result = false;
			return false;
		}
		return true;
	}

	/**
	 * @param object $value
	 * @param JCContent $content
	 * @return bool
	 */
	private static function onArticleChangeComplete( $value, $content = null ) {
		if ( $value && ( !$content || is_a( $content, 'JsonConfig\JCContent' ) ) ) {
			/** @var TitleValue $tv */
			if ( method_exists( $value, 'getTitleValue') ) {
				$tv = $value->getTitleValue();
			} elseif ( method_exists( $value, 'getTitle')) {
				$tv = $value->getTitle()->getTitleValue();
			} else {
				wfLogWarning( 'Unknown object type ' . gettype( $value ) );
				return true;
			}

			$conf = self::getMetadata( $tv );
			if ( $conf && $conf->store ) {
				$store = new JCCache( $tv, $conf, $content );
				$store->resetCache();

				// Handle remote site notification
				if ( $conf->store->notifyUrl ) {
					$store = $conf->store;
					$req =
						JCUtils::initApiRequestObj( $store->notifyUrl, $store->notifyUsername,
							$store->notifyPassword );
					if ( $req ) {
						$query = array(
							'format' => 'json',
							'action' => 'jsonconfig',
							'command' => 'reload',
							'title' => $tv->getNamespace() . ':' . $tv->getDBkey(),
						);
						JCUtils::callApi( $req, $query, 'notify remote JsonConfig client' );
					}
				}
			}
		}
		return true;
	}

	public static function onUnitTestsList( &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/../tests/phpunit/*Test.php' ) );
		return true;
	}
}
