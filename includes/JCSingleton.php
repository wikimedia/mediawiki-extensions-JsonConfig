<?php
namespace JsonConfig;

use ContentHandler;
use MWException;
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

		// Make sure no one else defined handlers for the same modelId
		foreach ( $wgJsonConfigModels as $modelId => $class ) {
			if ( array_key_exists( $modelId, $wgContentHandlers ) ) {
				throw new MWException( "JsonConfig: ModelID '$modelId' => '$class' is already " .
					"registered to '$wgContentHandlers[$modelId]' " );
			}
		}

		foreach ( $wgJsonConfigs as $confId => &$conf ) {
			if ( !is_string( $confId ) || $confId === '' ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId'], the key must be a string" );
				continue;
			}
			$modelId = array_key_exists( 'model', $conf ) ? ( $conf['model'] ? : $defaultModelId ) : $confId;
			$conf['model'] = $modelId;

			$ns = self::getConfVal( $conf, 'namespace', NS_CONFIG );
			$name = self::getConfVal( $conf, 'name', '' );
			$issubspace = self::getConfVal( $conf, 'issubspace', false );
			self::getConfVal( $conf, 'flaggedrevs', null );
			$islocal = self::getConfVal( $conf, 'islocal', false );
			$url = self::getConfVal( $conf, 'url', $wgJsonConfigApiUrl );

			// Decide if matching configs should be stored on this wiki
			$storeHere = $islocal || $wgJsonConfigStorage === true ||
				( is_array( $wgJsonConfigStorage ) && in_array( $confId, $wgJsonConfigStorage ) );
			$conf['storehere'] = $storeHere;

			// We could have thrown MWException here, but we would rather fail gracefully on a mis-configured server
			if ( !is_int( $ns ) || !is_string( $name ) || !is_bool( $issubspace ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId'], please check documentation" );
				continue;
			}
			if ( $ns % 2 !== 0 ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
					"Namespace $ns should be an even number" );
				continue;
			}
			if ( $url === false && !$storeHere ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
					"API URL is not set, and this config is not being stored locally" );
				continue;
			}
			if ( !array_key_exists( $modelId, $wgJsonConfigModels ) && $modelId !== $defaultModelId ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
					"Model '$modelId' is not defined in \$wgJsonConfigModels" );
				continue;
			}
			// Even though we might be able to override default content model for namespace, lets keep things clean
			if ( array_key_exists( $ns, $wgNamespaceContentModels ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: Namespace $ns is already " .
					"set to handle model '$wgNamespaceContentModels[$ns]'" );
				continue;
			}
			// If nsname is given, add it to the list, together with the talk page
			// Otherwise, create a placeholder for it
			if ( array_key_exists( 'nsname', $conf ) ) {
				if ( $ns === NS_CONFIG ) {
					wfLogWarning( "JsonConfig: Parameter 'nsname' in \$wgJsonConfigs['$confId'] is not supported " .
						'for namespace NS_CONFIG (' . NS_CONFIG . ')' );
				} else {
					$nsname = $conf['nsname'];
					if ( !is_string( $nsname ) || $nsname === '' ) {
						wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: " .
							"if given, nsname must be a string" );
						continue;
					} elseif ( array_key_exists( $ns, self::$namespaces ) &&
						self::$namespaces[$ns] !== null
					) {
						wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] - nsname has already " .
							"been set for namespace $ns" );
					} else {
						self::$namespaces[$ns] = $nsname;
						self::$namespaces[$ns + 1] = array_key_exists( 'nstalk', $conf ) ?
							$conf['nstalk'] : ( $nsname . '_talk' );
					}
				}
			} elseif ( !array_key_exists( $ns, self::$namespaces ) ) {
				self::$namespaces[$ns] = null;
			}

			unset( $nsVals );
			if ( !array_key_exists( $ns, self::$titleMap ) ) {
				$nsVals = array();
				self::$titleMap[$ns] = &$nsVals;
			} else {
				$nsVals = & self::$titleMap[$ns];
			}

			unset( $nameVals );
			$nameExists = array_key_exists( $name, $nsVals );
			if ( $name === '' ) {
				// Wildcard - the entire namespace is handled by this modelId, unless more specific name is set
				if ( $nameExists ) {
					wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] cannot define " .
						"another nameless handler for namespace $ns" );
					continue;
				} else {
					$nsVals[$name] = $conf;
				}
			} else {
				if ( !$nameExists ) {
					$nameVals = array();
					$nsVals[$name] = &$nameVals;
				} else {
					$nameVals = &$nsVals[$name];
				}
				$issubspace = $issubspace ? 1 : 0;
				if ( array_key_exists( $issubspace, $nameVals ) ) {
					wfLogWarning( "JsonConfig: \$wgJsonConfigs['$confId'] duplicates $ns:$name:isSubspace " .
						"value - there must be no more than one 'true' and 'false'" );
					continue;
				}
				$nameVals[$issubspace] = $conf;
			}
		}

		// Add all undeclared namespaces
		$missingNs = 1;
		foreach ( self::$namespaces as $ns => $nsname ) {
			if ( $nsname === null ) {
				$nsname = 'Config';
				if ( $ns !== NS_CONFIG ) {
					$nsname .= $missingNs;
					wfLogWarning( "JsonConfig: Namespace $ns does not have 'nsname' defined, using '$nsname'" );
					$missingNs += 1;
				}
				self::$namespaces[$ns] = $nsname;
				self::$namespaces[$ns + 1] = $nsname . '_talk';
			}
		}
	}

	/**
	 * Helper function to check if configuration has a field set, and if not, set it to default
	 * @param array $conf
	 * @param string $field
	 * @param mixed $default
	 * @return mixed
	 */
	private static function getConfVal( &$conf, $field, $default ) {
		if ( array_key_exists( $field, $conf ) ) {
			return $conf[$field];
		}
		$conf[$field] = $default;

		return $default;
	}

	/**
	 * Returns an array with title settings if the $title object is handled by the JsonConfig extension,
	 * false if unrecognized namespace, and null if namespace is handled but not this title
	 * @param Title $title
	 * @return array|false|null
	 */
	public static function getConfig( Title $title ) {
		static $lastTitle = null;
		static $lastResult = false;
		if ( $title === $lastTitle ) {
			return $lastResult;
		}
		$lastTitle = $title;
		self::init();
		if ( $title ) {
			// array of:  { namespace => { name => { allows-sub-namespaces => config } } }
			$key = $title->getNamespace();
			if ( array_key_exists( $key, self::$titleMap ) ) {
				$arr = self::$titleMap[$key];
				$parts = explode( ':', $title->getText(), 2 );
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
		$conf = self::getConfig( $title );
		if ( $conf ) {
			$modelId = $conf['model'];

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
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON || self::getConfig( $title ) ) {
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
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON || self::getConfig( $title ) ) {
			$out->addModules( 'ext.jsonConfig' );
		}

		return true;
	}

	/**
	 * Handle save complete to reset cache
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 * @param \WikiPage $article
	 * @param $user
	 * @param $content
	 * @param $summary
	 * @param $isMinor
	 * @param $isWatch
	 * @param $section
	 * @param $flags
	 * @param $revision
	 * @param $status
	 * @param $baseRevId
	 * @return bool
	 */
	public static function onPageContentSaveComplete( $article, $user, $content, $summary, $isMinor, $isWatch,
		$section, $flags, $revision, $status, $baseRevId ) {
		if ( is_a( $content, 'JsonConfig\JCContent' ) ) {
			$store = self::getCachedStore( $article->getTitle() );
			if ( $store ) {
				$store->resetCache();
			}
		}

		return true;
	}

	/**
	 * Prohibit creation of the pages that are part of our namespaces but have not been explicitly allowed
	 */
	public static function onUserCan( &$title, &$user, $action, &$result = null ) {
		if ( $action === 'create' && self::getConfig( $title ) === null ) {
			// prohibit creation of the pages for the namespace that we handle,
			// if the title is not matching declared rules
			$result = false;

			return false;
		}

		return true;
	}

	/**
	 * Get cache object for storage and retrieval of the data under given title
	 * @param Title $title
	 * @return bool|JCCache Returns false if the title is not handled by the settings
	 */
	public static function getCachedStore( Title $title ) {
		$conf = self::getConfig( $title );
		if ( !$conf ) {
			return false;
		}

		return new JCCache( $title, $conf );
	}

	/**
	 * Get content object for the given title
	 * @param Title $title
	 * @return bool|JCContent Returns false if the title is not handled by the settings
	 */
	public static function getContent( Title $title ) {
		$conf = self::getConfig( $title );
		if ( $conf ) {
			$store = new JCCache( $title, $conf );
			$content = $store->get();
			if ( $content !== false ) {
				// Convert string to the content object if needed
				if ( is_string( $content ) ) {
					$handler = new JCContentHandler( $conf['model'] );
					$content = $handler->unserializeContent( $content, null, false );
				}

				return $content;
			}
		}

		return false;
	}
}
