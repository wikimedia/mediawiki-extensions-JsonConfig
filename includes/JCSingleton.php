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
	 * @var array containing all the namespaces handled by JsonConfig
	 * Maps namespace id (int) => namespace name (string)
	 */
	static $namespaces = array();

	/**
	 * Initializes singleton state by parsing $wgJsonConfig* values
	 * @throws \MWException
	 */
	private static function init() {
		static $isInitialized = false;
		$defaultModelId = 'JsonConfig';
		if ( $isInitialized ) {
			return;
		}
		global $wgNamespaceContentModels, $wgContentHandlers;
		global $wgJsonConfigs, $wgJsonConfigModels, $wgJsonConfigApiUrl, $wgJsonConfigStorage;
		$isInitialized = true;

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
			if ( false === self::getConfObject( $conf, $confId ) ) {
				continue;
			}

			$modelId = isset( $conf->model ) ? ( $conf->model ? : $defaultModelId ) : $confId;
			if ( !array_key_exists( $modelId, $wgJsonConfigModels ) && $modelId !== $defaultModelId ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
				              "Model '$modelId' is not defined in \$wgJsonConfigModels" );
				continue;
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

			// Decide if matching configs should be stored on this wiki
			$storeHere =
				$islocal || $wgJsonConfigStorage === true ||
				( is_array( $wgJsonConfigStorage ) && is_string( $confId ) &&
				  in_array( $confId, $wgJsonConfigStorage ) );
			$conf->storeHere = $storeHere;

			if ( !$storeHere ) {
				if ( false === ( $remote = self::getConfObject( $conf, 'remote', $confId ) ) ) {
					continue;
				}
				if ( self::getConfVal( $remote, 'url', $wgJsonConfigApiUrl ) === '' ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']['url']: " .
					              "API URL is not set, and this config is not being stored locally" );
					continue;
				}
				self::getConfVal( $remote, 'username', '' );
				self::getConfVal( $remote, 'password', '' );
			} else {
				$conf->remote = null;
				if ( false === ( $store = self::getConfObject( $conf, 'store', $confId ) ) ) {
					continue;
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
				if ( isset( $conf->nsName ) ) {
					if ( $ns === NS_CONFIG ) {
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
							self::$namespaces[$ns + 1] = isset( $conf->nsTalk ) ? $conf->nsTalk : ( $nsName . '_talk' );
						}
					}
				} elseif ( !array_key_exists( $ns, self::$namespaces ) ) {
					self::$namespaces[$ns] = null;
				}
			}

			unset( $nsVals );
			if ( !array_key_exists( $ns, self::$titleMap ) ) {
				$nsVals = array();
				self::$titleMap[$ns] = &$nsVals;
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
					$nsVals[$conf->name] = $conf;
				}
			} else {
				if ( !$nameExists ) {
					$nameVals = array();
					$nsVals[$conf->name] = &$nameVals;
				} else {
					$nameVals = &$nsVals[$conf->name];
				}
				$isSubspace = $conf->isSubspace ? 1 : 0;
				if ( array_key_exists( $isSubspace, $nameVals ) ) {
					wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] duplicates $ns:$conf->name:isSubspace " .
					              "value - there must be no more than one 'true' and 'false'" );
					continue;
				}
				$nameVals[$isSubspace] = $conf;
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
	private static function getConfVal( &$conf, $field, $default ) {
		if ( property_exists( $conf, $field ) ) {
			return $conf->$field;
		}
		$conf->$field = $default;
		return $default;
	}

	/**
	 * Helper function to check if configuration has a field set, and if not, set it to default
	 */
	private static function getConfObject( & $value, $field, $confId = null ) {
		if ( !$confId ) {
			$val = & $value;
		} else {
			if ( !property_exists( $value, $field ) ) {
				$value->$field = null;
			}
			$val = & $value->$field;
		}
		if ( $val === null ) {
			$val = new stdClass();
		} elseif ( is_array( $val ) ) {
			$val = (object)$val;
		} elseif ( !is_object( $val ) ) {
			wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs" . ( $confId ? "['$confId']" : "" ) .
			              "['$field'], the value must be either an array or an object" );
			return null;
		}
		return $val;
	}

	/**
	 * Mostly for debugging purposes, this function returns initialized internal JsonConfig settings
	 * @return array
	 */
	public static function getTitleMap() {
		self::init();
		return self::$titleMap;
	}

	/**
	 * Returns an array with settings if the $titleValue object is handled by the JsonConfig extension,
	 * false if unrecognized namespace, and null if namespace is handled but not this title
	 * @param TitleValue $titleValue
	 * @return stdClass|false|null
	 */
	public static function getSettings( TitleValue $titleValue ) {
		static $lastTitle = null;
		static $lastResult = false;
		if ( $titleValue === $lastTitle ) {
			return $lastResult;
		}
		$lastTitle = $titleValue;
		// @fixme: why check for $titleValue?
		if ( $titleValue ) {
			// array of:  { namespace => { name => { allows-sub-namespaces => config } } }
			$key = $titleValue->getNamespace();
			$map = self::getTitleMap();
			if ( array_key_exists( $key, $map ) ) {
				$arr = $map[$key];
				$parts = explode( ':', $titleValue->getText(), 2 );
				if ( array_key_exists( $parts[0], $arr ) ) {
					$arr = $arr[$parts[0]];
					$key = count( $parts ) == 2 ? 1 : 0;
					if ( array_key_exists( $key, $arr ) ) {
						$lastResult = $arr[$key];
						return $lastResult;
					}
				}
				if ( array_key_exists( '', $arr ) ) {
					// all configs in this namespace are allowed
					$lastResult = $arr[''];
					return $lastResult;
				}
				// We know about the namespace, but there is no specific configuration
				$lastResult = null;
				return $lastResult;
			}
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
			if ( array_key_exists( $ns, $namespaces ) ) {
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
		$conf = self::getSettings( $title->getTitleValue() );
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
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON || self::getSettings( $title->getTitleValue() ) ) {
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
	static function onEditFilterMergedContent( $context, $content, $status, $summary, $user, $minoredit ) {
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
	static function onBeforePageDisplay( &$out, &$skin ) {
		$title = $out->getTitle();
		$handler = ContentHandler::getForModelID( $title->getContentModel() );
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON ||
			self::getSettings( $title->getTitleValue() )
		) {
			$out->addModules( 'ext.jsonConfig' );
		}
		return true;
	}

	public static function onAbortMove( Title $title, Title $newTitle, $wgUser, &$err, $reason ) {
		$conf = self::getSettings( $title->getTitleValue() );
		if ( $conf ) {
			$newConf = self::getSettings( $newTitle->getTitleValue() );
			if ( !$newConf ) {
				// @todo: is parse() the right func to use here?
				$err = wfMessage( 'jsonconfig-move-aborted-ns' )->parse();
				return false;
			} elseif ( $conf->model !== $newConf->model ) {
				$err = wfMessage( 'jsonconfig-move-aborted-model', $conf->model, $newConf->model )->parse();
				return false;
			}
		}
		return true;
	}

	public static function onPageContentSaveComplete( $article, $user, $content, $summary, $isMinor, $isWatch,
		$section, $flags, $revision, $status, $baseRevId ) {
		return self::onArticleChangeComplete( $article, $content );
	}

	public static function onArticleDeleteComplete( $article, &$user, $reason, $id, $content, $logEntry ) {
		return self::onArticleChangeComplete( $article );
	}

	public static function onArticleUndelete( $title, $created, $comment, $oldPageId ) {
		return self::onArticleChangeComplete( $title );
	}

	public static function onTitleMoveComplete( $title, $newTitle, $wgUser, $pageid, $redirid, $reason ) {
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
	public static function onuserCan( &$title, &$user, $action, &$result = null ) {
		if ( $action === 'create' && self::getSettings( $title->getTitleValue() ) === null ) {
			// prohibit creation of the pages for the namespace that we handle,
			// if the title is not matching declared rules
			$result = false;
			return false;
		}
		return true;
	}

	/**
	 * Get content object for the given title
	 * @param TitleValue $titleValue
	 * @return bool|JCContent Returns false if the title is not handled by the settings
	 */
	public static function getContent( TitleValue $titleValue ) {
		$conf = self::getSettings( $titleValue );
		if ( $conf ) {
			$store = new JCCache( $titleValue, $conf );
			$content = $store->get();
			if ( $content !== false ) {
				// Convert string to the content object if needed
				if ( is_string( $content ) ) {
					$handler = new JCContentHandler( $conf->model );
					$content = $handler->unserializeContent( $content, null, false );
				}
				return $content;
			}
		}
		return false;
	}

	/**
	 * @param object $value
	 * @param string $content
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

			$conf = self::getSettings( $tv );
			if ( $conf && $conf->storeHere ) {
				$store = new JCCache( $tv, $conf, $content );
				$store->resetCache();

				// Handle remote site notification
				if ( $conf->store->notifyUrl ) {
					$store = $conf->store;
					$req =
						JCUtils::initApiRequestObj( $store->notifyUrl, $store->notifyUsername,
							$store->notifyPassword );
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
		return true;
	}
}
