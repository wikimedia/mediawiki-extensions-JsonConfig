<?php
namespace JsonConfig;

use ContentHandler;
use MWException;
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
	 * @throws \MWException
	 */
	private static function init() {
		static $isInitialized = false;
		if ( $isInitialized ) {
			return;
		}
		global $wgNamespaceContentModels, $wgContentHandlers, $wgJsonConfigs, $wgJsonConfigModels;
		$isInitialized = true;
		$defaultModelId = 'JsonConfig';

		$badId = null;
		foreach ( $wgJsonConfigs as $confId => &$conf ) {
			// Unset previous iteration item if it failed validation ('continue' was used)
			if ( $badId !== null ) {
				unset( $wgJsonConfigs[$badId] );
			}
			$badId = $confId;

			if ( !is_string( $confId ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId'], the key must be a string" );
				continue;
			}
			if ( null === self::getConfObject( $conf, $confId ) ) {
				continue; // warned inside the function
			}

			$modelId = property_exists( $conf, 'model' ) ? ( $conf->model ? : $defaultModelId ) : $confId;
			if ( !array_key_exists( $modelId, $wgJsonConfigModels ) ) {
				if ( $modelId === $defaultModelId ) {
					$wgJsonConfigModels[$defaultModelId] = null;
				} else {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
					              "Model '$modelId' is not defined in \$wgJsonConfigModels" );
					continue;
				}
			}
			if ( array_key_exists( $modelId, $wgContentHandlers ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: Model '$modelId' is " .
				              "already registered in \$wgContentHandlers to {$wgContentHandlers[$modelId]}" );
				continue;
			}
			$conf->model = $modelId;

			$ns = self::getConfVal( $conf, 'namespace', NS_CONFIG );
			if ( !is_int( $ns ) || $ns % 2 !== 0 ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
				              "Namespace $ns should be an even number" );
				continue;
			}
			// Even though we might be able to override default content model for namespace, lets keep things clean
			if ( array_key_exists( $ns, $wgNamespaceContentModels ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: Namespace $ns is already " .
				              "set to handle model '$wgNamespaceContentModels[$ns]'" );
				continue;
			}

			// nsName & nsTalk are handled later
			self::getConfVal( $conf, 'name', '' );
			self::getConfVal( $conf, 'isSubspace', false );
			$islocal = self::getConfVal( $conf, 'isLocal', false );
			self::getConfVal( $conf, 'cacheExp', 24 * 60 * 60 );
			self::getConfVal( $conf, 'cacheKey', '' );
			self::getConfVal( $conf, 'flaggedRevs', false );
			$isSharedNs = false;

			// Decide if matching configs should be stored on this wiki
			$storeHere = $islocal || property_exists( $conf, 'store' );
			if ( !$storeHere ) {
				$conf->store = false; // 'store' does not exist, use it as a flag to indicate remote storage
				if ( null === ( $remote = self::getConfObject( $conf, 'remote', $confId, 'url' ) ) ) {
					continue; // warned inside the function
				}
				if ( self::getConfVal( $remote, 'url', '' ) === '' ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']['remote']['url']: " .
					              "API URL is not set, and this config is not being stored locally" );
					continue;
				}
				self::getConfVal( $remote, 'username', '' );
				self::getConfVal( $remote, 'password', '' );
			} else {
				if ( property_exists( $conf, 'remote' ) ) {
					// non-fatal -- simply ignore the 'remote' setting
					wfLogWarning( "JsonConfig: In \$wgJsonConfigs['$confId']['remote'] is set for the config that will be stored on this wiki. 'remote' parameter will be ignored." );
				}
				$conf->remote = null;
				if ( null === ( $store = self::getConfObject( $conf, 'store', $confId ) ) ) {
					continue; // warned inside the function
				}
				self::getConfVal( $store, 'cacheNewValue', true );
				self::getConfVal( $store, 'notifyUrl', '' );
				self::getConfVal( $store, 'notifyUsername', '' );
				self::getConfVal( $store, 'notifyPassword', '' );
			}

			// Too lazy to write proper error messages for all parameters.
			if ( ( isset( $conf->nsTalk ) && !is_string( $conf->nsTalk ) ) || !is_string( $conf->name ) ||
			     !is_bool( $conf->isSubspace ) || !is_bool( $islocal ) || !is_int( $conf->cacheExp ) ||
			     !is_string( $conf->cacheKey ) || !is_bool( $conf->flaggedRevs )
			) {
				wfLogWarning( "JsonConfig: Invalid type of one of the parameters in \$wgJsonConfigs['$confId'], please check documentation" );
				continue;
			}
			if ( isset( $remote ) ) {
				if ( !is_string( $remote->url ) || !is_string( $remote->username ) ||
				     !is_string( $remote->password )
				) {
					wfLogWarning( "JsonConfig: Invalid type of one of the parameters in \$wgJsonConfigs['$confId']['remote'], please check documentation" );
					continue;
				}
			}
			if ( isset( $store ) ) {
				if ( !is_bool( $store->cacheNewValue ) || !is_string( $store->notifyUrl ) ||
				     !is_string( $store->notifyUsername ) || !is_string( $store->notifyPassword )
				) {
					wfLogWarning( "JsonConfig: Invalid type of one of the parameters in \$wgJsonConfigs['$confId']['store'], please check documentation" );
					continue;
				}
			}
			if ( $storeHere ) {
				// If nsName is given, add it to the list, together with the talk page
				// Otherwise, create a placeholder for it
				if ( property_exists( $conf, 'nsName' ) ) {
					if ( $conf->nsName === false ) {
						// Non JC-specific namespace, don't register it
						if ( !array_key_exists( $ns, self::$namespaces ) ) {
							self::$namespaces[$ns] = false;
						}
						$isSharedNs = true;
				    } elseif ( $ns === NS_CONFIG ) {
						wfLogWarning( "JsonConfig: Parameter 'nsName' in \$wgJsonConfigs['$confId'] is not " .
						              'supported for namespace == NS_CONFIG (' . NS_CONFIG . ')' );
					} else {
						$nsName = $conf->nsName;
						if ( !is_string( $nsName ) || $nsName === '' ) {
							wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
							              "if given, nsName must be a string" );
							continue;
						} elseif ( array_key_exists( $ns, self::$namespaces ) &&
						           self::$namespaces[$ns] !== null
						) {
							wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] - nsName has already " .
							              "been set for namespace $ns" );
						} else {
							self::$namespaces[$ns] = $nsName;
							self::$namespaces[$ns + 1] =
								isset( $conf->nsTalk ) ? $conf->nsTalk : ( $nsName . '_talk' );
						}
					}
				} elseif ( !array_key_exists( $ns, self::$namespaces ) || self::$namespaces[$ns] === false ) {
					self::$namespaces[$ns] = null;
				}
			}

			unset( $nsVals );
			if ( !array_key_exists( $ns, self::$titleMap ) ) {
				$nsVals = array();
				self::$titleMap[$ns] = & $nsVals;
			} else {
				$nsVals = & self::$titleMap[$ns];
			}

			unset( $nameVals );
			$nameExists = array_key_exists( $conf->name, $nsVals );
			if ( $conf->name === '' ) {
				// Wildcard - the entire namespace is handled by this modelId, unless more specific name is set
				if ( $conf->isSubspace ) {
					wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId']->isSubspace is true, but name is not set" );
					continue;
				} elseif ( $nameExists ) {
					wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] cannot define " .
					              "another nameless handler for namespace $ns" );
					continue;
				} else {
					$nsVals[''] = $conf;
				}
			} else {
				if ( !$nameExists ) {
					$nameVals = array();
					$nsVals[$conf->name] = & $nameVals;
				} else {
					$nameVals = & $nsVals[$conf->name];
				}
				$subKey = $conf->isSubspace ? 'sub' : 'val'; // subspace or a direct value
				if ( array_key_exists( $subKey, $nameVals ) ) {
					wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] duplicates $ns:$conf->name:isSubspace " .
					              "value - there must be no more than one 'true' and 'false'" );
					continue;
				}
				$nameVals[$subKey] = $conf;
			}
			if ( $isSharedNs ) {
				// this namespace is shared with core or other extensions, and has to be declared somewhere
				$nsVals['_'] = true;
			}

			$badId = null; // Item passed validation, keep it
		}
		if ( $badId !== null ) {
			unset( $wgJsonConfigs[$badId] );
		}

		// Add all undeclared namespaces
		$missingNs = 1;
		foreach ( self::$namespaces as $ns => $nsName ) {
			if ( $nsName === null ) {
				$nsName = 'Config';
				if ( $ns !== NS_CONFIG ) {
					$nsName .= $missingNs;
					wfLogWarning( "JsonConfig: Namespace $ns does not have 'nsName' defined, using '$nsName'" );
					$missingNs += 1;
				}
				self::$namespaces[$ns] = $nsName;
				self::$namespaces[$ns + 1] = $nsName . '_talk';
			}
		}
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
	 * @param $value
	 * @param string $field
	 * @param string $confId
	 * @param string $treatAsField
	 * @return null|object|\stdClass
	 */
	private static function getConfObject( & $value, $field, $confId = null, $treatAsField = null ) {
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
			wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs" . ( $confId ? "['$confId']" : "" ) .
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
		$key = $titleValue->getNamespace();
		/** @var array[] $map array of:  { namespace => { name => { allows-sub-namespaces => config } } }
		 'name' could be: name of the page, name of the sub-namespace,
		 * an empty string if entire namespace is taken, or a { '_' => true } to mean that namespace is shared
		 */
		$map = self::getTitleMap();
		if ( array_key_exists( $key, $map ) ) {
			$arr = $map[$key];
			$parts = explode( ':', $titleValue->getText(), 2 );
			if ( array_key_exists( $parts[0], $arr ) ) {
				$arr = $arr[$parts[0]];
				$key = count( $parts ) == 2 ? 'sub' : 'val'; // subspace or a direct value
				if ( array_key_exists( $key, $arr ) ) {
					$lastResult = $arr[$key];
					return $lastResult;
				}
			}
			if ( array_key_exists( '', $arr ) ) {
				// all configs in this namespace are allowed
				$lastResult = $arr[''];
				return $lastResult;
			} if ( !array_key_exists( '_', $arr ) ) {
				// We know about the namespace, but there is no specific configuration
				$lastResult = null;
				return $lastResult;
			}
			// else we know about the namespace, but it is shared with others
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
			$out->addModules( 'ext.jsonConfig' );
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
