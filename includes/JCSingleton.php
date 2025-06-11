<?php
namespace JsonConfig;

use InvalidArgumentException;
use MapCacheLRU;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use stdClass;

/**
 * Static utility methods and configuration page hook handlers for JsonConfig extension.
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @author Yuri Astrakhan
 * @copyright © 2013 Yuri Astrakhan
 * @license GPL-2.0-or-later
 */
class JCSingleton {

	/**
	 * @var array<int,stdClass[]> describes how a title should be handled by JsonConfig extension.
	 * The structure is an array of array of ...:
	 * { int_namespace => { name => { allows-sub-namespaces => configuration_array } } }
	 */
	public static $titleMap = [];

	/**
	 * @var array<int,string|false> containing all the namespaces handled by JsonConfig
	 * Maps namespace id (int) => namespace name (string).
	 * If false, presumes the namespace has been registered by core or another extension
	 */
	public static $namespaces = [];

	/**
	 * @var array<int,MapCacheLRU> contains a cache of recently resolved JCTitle's
	 *   as namespace => MapCacheLRU
	 */
	public static $titleMapCacheLru = [];

	/**
	 * @var array<int,MapCacheLRU> contains a cache of recently requested content objects
	 *   as namespace => MapCacheLRU
	 */
	public static $mapCacheLru = [];

	/**
	 * Initializes singleton state by parsing $wgJsonConfig* values
	 * @param bool $force Force init, only usable in unit tests
	 */
	public static function init( $force = false ) {
		static $isInitialized = false;
		if ( $isInitialized && !$force ) {
			return;
		}
		if ( $force && !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new \LogicException( 'Can force init only in tests' );
		}
		$isInitialized = true;
		$config = MediaWikiServices::getInstance()->getMainConfig();
		[ self::$titleMap, self::$namespaces ] = self::parseConfiguration(
			$config->get( MainConfigNames::NamespaceContentModels ),
			$config->get( MainConfigNames::ContentHandlers ),
			array_replace_recursive(
				ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigs' ), $config->get( 'JsonConfigs' )
			),
			array_replace_recursive(
				ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigModels' ),
				$config->get( 'JsonConfigModels' )
			)
		);
	}

	/**
	 * @param array<int,string> $namespaceContentModels $wgNamespaceContentModels
	 * @param array<string,mixed> $contentHandlers $wgContentHandlers
	 * @param array<string,stdClass> $configs $wgJsonConfigs
	 * @param array<string,mixed> $models $wgJsonConfigModels
	 * @param bool $warn if true, calls wfLogWarning() for all errors
	 * @return array{0:array<int,stdClass[]>, 1:array<int,string|false>} [ $titleMap, $namespaces ]
	 */
	public static function parseConfiguration(
		array $namespaceContentModels, array $contentHandlers,
		array $configs, array $models, $warn = true
	) {
		$defaultModelId = 'JsonConfig';
		$warnFunc = $warn
			? 'wfLogWarning'
			: static function ( $msg ) {
			};

		$namespaces = [];
		$titleMap = [];
		foreach ( $configs as $confId => &$conf ) {
			if ( !is_string( $confId ) ) {
				$warnFunc(
					"JsonConfig: Invalid \$wgJsonConfigs['$confId'], the key must be a string"
				);
				continue;
			}
			if ( self::getConfObject( $warnFunc, $conf, $confId ) === null ) {
				continue; // warned inside the function
			}

			$modelId = property_exists( $conf, 'model' )
				? ( $conf->model ? : $defaultModelId ) : $confId;
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
			// Even though we might be able to override default content model for namespace,
			// lets keep things clean
			if ( array_key_exists( $ns, $namespaceContentModels ) ) {
				$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs['$confId']: Namespace $ns is " .
					"already set to handle model '$namespaceContentModels[$ns]'" );
				continue;
			}

			// nsName & nsTalk are handled later
			self::getConfVal( $conf, 'pattern', '' );
			self::getConfVal( $conf, 'cacheExp', 24 * 60 * 60 );
			self::getConfVal( $conf, 'cacheKey', '' );
			self::getConfVal( $conf, 'flaggedRevs', false );
			self::getConfVal( $conf, 'license', false );
			$islocal = self::getConfVal( $conf, 'isLocal', true );

			// Decide if matching configs should be stored on this wiki
			$storeHere = $islocal || property_exists( $conf, 'store' );
			if ( !$storeHere ) {
				// 'store' does not exist, use it as a flag to indicate remote storage
				$conf->store = false;
				$remote = self::getConfObject( $warnFunc, $conf, 'remote', $confId, 'url' );
				if ( $remote === null ) {
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
					$warnFunc( "JsonConfig: In \$wgJsonConfigs['$confId']['remote'] is set for " .
						"the config that will be stored on this wiki. " .
						"'remote' parameter will be ignored."
					);
				}
				$conf->remote = null;
				$store = self::getConfObject( $warnFunc, $conf, 'store', $confId );
				if ( $store === null ) {
					continue; // warned inside the function
				}
				self::getConfVal( $store, 'cacheNewValue', true );
				self::getConfVal( $store, 'notifyUrl', '' );
				self::getConfVal( $store, 'notifyUsername', '' );
				self::getConfVal( $store, 'notifyPassword', '' );
			}

			// Too lazy to write proper error messages for all parameters.
			if ( ( isset( $conf->nsTalk ) && !is_string( $conf->nsTalk ) ) ||
				!is_string( $conf->pattern ) ||
				!is_bool( $islocal ) || !is_int( $conf->cacheExp ) || !is_string( $conf->cacheKey )
				|| !is_bool( $conf->flaggedRevs )
			) {
				$warnFunc( "JsonConfig: Invalid type of one of the parameters in " .
					"\$wgJsonConfigs['$confId'], please check documentation" );
				continue;
			}
			if ( isset( $remote ) ) {
				if ( !is_string( $remote->url ) || !is_string( $remote->username ) ||
					!is_string( $remote->password )
				) {
					$warnFunc( "JsonConfig: Invalid type of one of the parameters in " .
						"\$wgJsonConfigs['$confId']['remote'], please check documentation" );
					continue;
				}
			}
			if ( isset( $store ) ) {
				if ( !is_bool( $store->cacheNewValue ) || !is_string( $store->notifyUrl ) ||
					!is_string( $store->notifyUsername ) || !is_string( $store->notifyPassword )
				) {
					$warnFunc( "JsonConfig: Invalid type of one of the parameters in " .
						" \$wgJsonConfigs['$confId']['store'], please check documentation" );
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
						$warnFunc( "JsonConfig: Parameter 'nsName' in \$wgJsonConfigs['$confId'] " .
							"is not supported for namespace $ns (NS_CONFIG)" );
					} else {
						$nsName = $conf->nsName;
						$nsTalk = $conf->nsTalk ?? $nsName . '_talk';
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
							$namespaces[$ns + 1] = $conf->nsTalk ?? $nsName . '_talk';
						}
					}
				} elseif ( !array_key_exists( $ns, $namespaces ) || $namespaces[$ns] === false ) {
					$namespaces[$ns] = null;
				}
			}

			if ( !array_key_exists( $ns, $titleMap ) ) {
				$titleMap[$ns] = [ $conf ];
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
					$warnFunc(
						"JsonConfig: Namespace $ns does not have 'nsName' defined, using '$nsName'"
					);
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
	 * @param stdClass &$conf
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
	 * @param callable $warnFunc
	 * @param stdClass &$value
	 * @param string $field
	 * @param string|null $confId
	 * @param string|null $treatAsField
	 * @return null|stdClass
	 */
	private static function getConfObject(
		$warnFunc, &$value, $field, $confId = null, $treatAsField = null
	) {
		if ( !$confId ) {
			$val = & $value;
		} else {
			if ( !property_exists( $value, $field ) ) {
				$value->$field = null;
			}
			$val = & $value->$field;
		}
		if ( $val === null || $val === true ) {
			$val = (object)[];
		} elseif ( is_array( $val ) ) {
			$val = (object)$val;
		} elseif ( is_string( $val ) && $treatAsField !== null ) {
			// treating this string value as a sub-field
			$val = (object)[ $treatAsField => $val ];
		} elseif ( !is_object( $val ) ) {
			$warnFunc( "JsonConfig: Invalid \$wgJsonConfigs" . ( $confId ? "['$confId']" : "" ) .
				"['$field'], the value must be either an array or an object" );
			return null;
		}
		return $val;
	}

	/**
	 * Get content object from the local LRU cache, or null if doesn't exist
	 * @param TitleValue $titleValue
	 * @return null|JCContent
	 */
	public static function getContentFromLocalCache( TitleValue $titleValue ) {
		// Some of the titleValues are remote, and their namespace might not be declared
		// in the current wiki. Since TitleValue is a content object, it does not validate
		// the existence of namespace, hence we use it as a simple storage.
		// Producing an artificial string key by appending (namespaceID . ':' . titleDbKey)
		// seems wasteful and redundant, plus most of the time there will be just a single
		// namespace declared, so this structure seems efficient and easy enough.
		if ( !array_key_exists( $titleValue->getNamespace(), self::$mapCacheLru ) ) {
			// TBD: should cache size be a config value?
			self::$mapCacheLru[$titleValue->getNamespace()] = $cache = new MapCacheLRU( 10 );
		} else {
			$cache = self::$mapCacheLru[$titleValue->getNamespace()];
		}

		return $cache->get( $titleValue->getDBkey() );
	}

	/**
	 * Get content object for the given title.
	 * Namespace ID does not need to be defined in the current wiki,
	 * as long as it is defined in $wgJsonConfigs.
	 * @param TitleValue|JCTitle $titleValue
	 * @return bool|JCContent Returns false if the title is not handled by the settings
	 */
	public static function getContent( TitleValue $titleValue ) {
		$content = self::getContentFromLocalCache( $titleValue );

		if ( $content === null ) {
			$jct = self::parseTitle( $titleValue );
			if ( $jct ) {
				$store = new JCCache( $jct );
				$content = $store->get();
				if ( is_string( $content ) ) {
					// Convert string to the content object if needed
					$handler = new JCContentHandler( $jct->getConfig()->model );
					$content = $handler->unserializeContent( $content, null, false );
				}
			} else {
				$content = false;
			}
			self::$mapCacheLru[$titleValue->getNamespace()]
				->set( $titleValue->getDBkey(), $content );
		}

		return $content;
	}

	/**
	 * Start up a JCContentLoader pipeline for the given title.
	 *
	 * Additional parameters (currently just the transform options) may be passed
	 * to the loader, and it will return a JCContentWrapper or a fatal error in a Status.
	 *
	 * This allows access to the transforms, and reports back any modified expiry time
	 * and dependency information that might need to be recorded with a more complex
	 * request than getContent() allows.
	 *
	 * Extensions that make use of Data: page fetches in their rendering pipelines and
	 * need to support global dependency tracking or Lua transforms should use this method.
	 *
	 * @param TitleValue $titleValue the Data: page to load, may be remote
	 * @return JCContentLoader for additional loading options and metadata
	 */
	public static function getContentLoader( TitleValue $titleValue ): JCContentLoader {
		$jct = self::parseTitle( $titleValue );
		return MediaWikiServices::getInstance()->getService( 'JsonConfig.ContentLoaderFactory' )->get( $jct );
	}

	/**
	 * Parse json text into a content object for the given title.
	 * Namespace ID does not need to be defined in the current wiki,
	 * as long as it is defined in $wgJsonConfigs.
	 * @param TitleValue $titleValue
	 * @param string $jsonText json content
	 * @param bool $isSaving if true, performs extensive validation during unserialization
	 * @return bool|JCContent Returns false if the title is not handled by the settings
	 */
	public static function parseContent( TitleValue $titleValue, $jsonText, $isSaving = false ) {
		$jct = self::parseTitle( $titleValue );
		if ( $jct ) {
			$handler = new JCContentHandler( $jct->getConfig()->model );
			return $handler->unserializeContent( $jsonText, null, $isSaving );
		}

		return false;
	}

	/**
	 * Mostly for debugging purposes, this function returns initialized internal JsonConfig settings
	 * @return array<int,stdClass[]> map of namespaceIDs to list of configurations
	 */
	public static function getTitleMap() {
		self::init();
		return self::$titleMap;
	}

	/**
	 * Get the name of the class for a given content model
	 * @param string $modelId
	 * @return string
	 * @phan-return class-string
	 */
	public static function getContentClass( $modelId ) {
		$configModels = array_replace_recursive(
			ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigModels' ),
			MediaWikiServices::getInstance()->getMainConfig()->get( 'JsonConfigModels' )
		);
		$class = null;
		if ( array_key_exists( $modelId, $configModels ) ) {
			$value = $configModels[$modelId];
			if ( is_array( $value ) ) {
				if ( !array_key_exists( 'class', $value ) ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigModels['$modelId'] array " .
						"value, 'class' not found" );
				} else {
					$class = $value['class'];
				}
			} else {
				$class = $value;
			}
		}
		if ( !$class ) {
			$class = JCContent::class;
		}
		return $class;
	}

	/**
	 * Given a title (either a user-given string, or as an object), return JCTitle
	 * @param Title|TitleValue|string $value
	 * @param int|null $namespace Only used when title is a string
	 * @return JCTitle|null|false false if unrecognized namespace,
	 * and null if namespace is handled but does not match this title
	 */
	public static function parseTitle( $value, $namespace = null ) {
		if ( $value === null || $value === '' || $value === false ) {
			// In some weird cases $value is null
			return false;
		} elseif ( $value instanceof JCTitle ) {
			// Nothing to do
			return $value;
		} elseif ( $namespace !== null && !is_int( $namespace ) ) {
			throw new InvalidArgumentException( '$namespace parameter must be either null or an integer' );
		}

		// figure out the namespace ID (int) - we don't need to parse the string if ns is unknown
		if ( $value instanceof LinkTarget ) {
			$namespace ??= $value->getNamespace();
		} elseif ( is_string( $value ) ) {
			if ( $namespace === null ) {
				throw new InvalidArgumentException( '$namespace parameter is missing for string $value' );
			}
		} else {
			wfLogWarning( 'Unexpected title param type ' . get_debug_type( $value ) );
			return false;
		}

		// Search title map for the matching configuration
		$map = self::getTitleMap();
		if ( array_key_exists( $namespace, $map ) ) {
			// Get appropriate LRU cache object
			if ( !array_key_exists( $namespace, self::$titleMapCacheLru ) ) {
				self::$titleMapCacheLru[$namespace] = $cache = new MapCacheLRU( 20 );
			} else {
				$cache = self::$titleMapCacheLru[$namespace];
			}

			// Parse string if needed
			// TODO: should the string parsing also be cached?
			if ( is_string( $value ) ) {
				$dbKey = self::normalizeTitleString( $value );
				if ( $dbKey === null ) {
					return null;
				}
			} else {
				$dbKey = $value->getDBkey();
			}

			// A bit weird here: cache will store JCTitle objects or false if the namespace
			// is known to JsonConfig but the dbkey does not match. But in case the title is not
			// handled, this function returns null instead of false if the namespace is known,
			// and false otherwise
			$result = $cache->get( $dbKey );
			if ( $result === null ) {
				$result = false;
				foreach ( $map[$namespace] as $conf ) {
					$re = $conf->pattern;
					if ( !$re || preg_match( $re, $dbKey ) ) {
						$result = new JCTitle( $namespace, $dbKey, $conf );
						break;
					}
				}

				$cache->set( $dbKey, $result );
			}

			// return null if the given namespace is mentioned in the config,
			// but title doesn't match
			return $result ?: null;

		} else {
			// return false if JC doesn't know anything about this namespace
			return false;
		}
	}

	/**
	 * Normalize a title string in a way that is similar to
	 * MediaWikiTitleCodec::splitTitleString(), but does not depend on local wiki config.
	 *
	 * @param string $text
	 * @return string|null
	 */
	private static function normalizeTitleString( string $text ) {
		// Decode character references (why??)
		$dbkey = Sanitizer::decodeCharReferencesAndNormalize( $text );

		// Collapse and normalize whitespace
		$dbkey = preg_replace(
			'/[ _\xA0\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u',
			'_',
			$dbkey
		);
		$dbkey = trim( $dbkey, '_' );

		// Strip initial colon
		if ( str_starts_with( $dbkey, ':' ) ) {
			$dbkey = substr( $dbkey, 1 );
			$dbkey = trim( $dbkey, '_' );
		}

		// Size must be between 1 and 255 bytes
		if ( $dbkey === '' || strlen( $dbkey ) > 255 ) {
			return null;
		}

		// Find illegal characters and sequences
		$rxTc = <<<REGEX
			<
			# Any character not allowed is forbidden
			[^%!"$&'()*,\-./0-9:;=?@A-Z\\\\^_`a-z~+\x{0080}-\x{10FFFF}]
			# URL percent encoding sequences are not allowed
			| %[0-9A-Fa-f]{2}
			# XML/HTML character references are not allowed
			| &[A-Za-z0-9\x{0080}-\x{10FFFF}]+;
			# Bidi and replacement characters are not allowed
			| [\x{200E}\x{200F}\x{202A}-\x{202E}\x{FFFD}]
			# Relative path components
			| (/ | ^) \.{1,2} (/ | $)
			# Signature-like sequences
			| ~~~
			# Initial colons
			| ^:
			>ux
			REGEX;
		// Reject matching titles or those that cause preg_match() failure
		if ( preg_match( $rxTc, $dbkey ) !== 0 ) {
			return null;
		}

		// At this point, only support wiki namespaces that capitalize title's first char,
		// but do not enable sub-pages.
		// This way data can already be stored on MediaWiki namespace everywhere, or
		// places like commons and zerowiki.
		// Another implicit limitation: there might be an issue if data is stored on a wiki
		// with the non-default ucfirst(), e.g. az, kaa, kk, tr -- they convert "i" to "İ"
		return MediaWikiServices::getInstance()->getLanguageFactory()
			->getLanguage( 'en' )
			->ucfirst( $dbkey );
	}

	/**
	 * Returns an array with settings if the $titleValue object is handled by the JsonConfig
	 * extension, false if unrecognized namespace,
	 * and null if namespace is handled but not this title
	 * @param TitleValue $titleValue
	 * @return stdClass|false|null
	 * @deprecated use JCSingleton::parseTitle() instead
	 */
	public static function getMetadata( $titleValue ) {
		$jct = self::parseTitle( $titleValue );
		return $jct ? $jct->getConfig() : $jct;
	}

	/**
	 * Record a JsonConfig data usage link for the given parser output;
	 * in a config with multiple wikis this will save into a shared
	 * globaljsonlinks table for propagation of cache updates and
	 * backlinks.
	 *
	 * Note: when using a JCContentWrapper, call its addToParserOutput()
	 * method rather than using this directly.
	 */
	public static function recordJsonLink( ParserOutput $parserOutput, TitleValue $title ) {
		// Note that remote namespaces may not exist locally!
		// These always refer to pages on the JsonConfig store wiki,
		// round-tripping TitleValues into strings.
		$pair = $title->getNamespace() . '|' . $title->getDBkey();
		$parserOutput->appendExtensionData( GlobalJsonLinks::KEY_JSONLINKS, $pair );
	}

	/**
	 * Extract the recorded list of target pages for cache invalidations via
	 * data-load logic. Pages may  be on a remote wiki, so be careful with
	 * namespaces.
	 *
	 * @return TitleValue[]
	 */
	public static function getJsonLinks( ParserOutput $parserOutput ): array {
		$links = array_keys( $parserOutput->getExtensionData( GlobalJsonLinks::KEY_JSONLINKS ) ?? [] );
		return array_map( static function ( $str ) {
			$bits = explode( '|', $str );
			if ( count( $bits ) > 1 ) {
				$namespace = intval( $bits[0] );
				$title = $bits[1];
			} else {
				// Old parser cache entries may have only supported NS_DATA deps.
				$namespace = NS_DATA;
				$title = $bits[0];
			}
			return new TitleValue( $namespace, $title );
		}, $links );
	}
}
