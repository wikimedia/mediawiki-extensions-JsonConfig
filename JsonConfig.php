<?php
/**
 * Extension JsonConfig
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @author Yuri Astrakhan <yurik@wikimedia.org>
 * @copyright © 2013 Yuri Astrakhan
 * @note Some of the code and ideas were based on Ori Livneh <ori@wikimedia.org> schema extension
 * @license GNU General Public Licence 2.0 or later
 */

// Needs to be called within MediaWiki; not standalone
if ( !defined( 'MEDIAWIKI' ) ) {
	echo( "This is a MediaWiki extension and cannot run standalone.\n" );
	die( -1 );
}

// Extension credits that will show up on Special:Version
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'JsonConfig',
	'version' => '0.1.0',
	'author' => array( 'Yuri Astrakhan' ),
	'descriptionmsg' => 'jsonconfig-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:JsonConfig',
);

define( 'NS_CONFIG', 482 );
define( 'NS_CONFIG_TALK', 483 );

$cwd = __DIR__ . DIRECTORY_SEPARATOR;
$wgMessagesDirs['JsonConfig'] = $cwd . 'i18n';

$cwd .= 'includes' . DIRECTORY_SEPARATOR;
foreach ( array(
	          'JCCache',
	          'JCContent',
	          'JCContentHandler',
	          'JCContentView',
	          'JCKeyValueContent',
	          'JCSingleton',
	          'JCValidators',
        ) as $class => $filename ) {
	$cls = is_string( $class ) ? $class : $filename;
	$wgAutoloadClasses['JsonConfig\\' . $cls] = $cwd . $filename . '.php';
}

// @todo: this entry should be done only if $wgJsonConfigEnabled === true && namespace is actually used by config
$cwd = __DIR__ . DIRECTORY_SEPARATOR;
$wgExtensionMessagesFiles['JsonConfigNamespaces'] = $cwd . 'JsonConfig.namespaces.php';

/**
 * Each extension should add its configuration profiles as described in the doc
 * https://www.mediawiki.org/wiki/Requests_for_comment/Json_Config_pages_in_wiki
 * @todo @fixme: change above URL to the extension page
 */
$wgJsonConfigs = array();

/**
 * Control which configuration profiles will be stored on this wiki:
 * If true, all profiles in $wgJsonConfigs are locally stored
 * If false, all profiles are remote
 * Otherwise, host only those profiles whose IDs are listed.
 * Setting 'islocal'=>true in the config profile overrides this setting
 * Having this setting helps with multi-site configuration, allowing exactly
 * the same profile to be used at both the storing and the using wikis.
 */
$wgJsonConfigStorage = array();

/**
 * Array of model ID => content class mappings
 * Each value could either be a string - a JCContent-derived class name
 * or an array:
 *    { 'content' => 'classname',  // derives from JCContent
 *      'view'    => 'classname' } // implements JCContentView
 */
$wgJsonConfigModels = array();

/**
 * Disable memcached caching (debugging)
 */
$wgJsonConfigDisableCache = false;

/**
 * MediaWiki API endpoint to call to get remote configuration
 */
$wgJsonConfigApiUrl = false;

/**
 * Change this value whenever the entire JsonConfig cache needs to be invalidated
 */
$wgJsonConfigCacheKeyPrefix = '';

/**
 * Quick check if the current wiki will store any configurations.
 * Faster than doing a full parsing of the $wgJsonConfigs in the JCSingleton::init()
 * @return bool
 */
function jsonConfigIsStorage() {
	static $isStorage = null;
	if ( $isStorage === null ) {
		global $wgJsonConfigStorage, $wgJsonConfigs;
		if ( $wgJsonConfigStorage ) {
			$isStorage = count( $wgJsonConfigs ) > 0;
		} else {
			$isStorage = false;
			foreach ( $wgJsonConfigs as $jc ) {
				if ( array_key_exists( 'islocal', $jc ) && $jc['islocal'] ) {
					$isStorage = true;
					break;
				}
			}
		}
	}

	return $isStorage;
}

// Registers hooks and resources which are only required on the config-hosting wiki.
$wgExtensionFunctions[] = function () {
	if ( !jsonConfigIsStorage() ) {
		return;
	}

	global $wgResourceModules, $wgHooks;
	$wgResourceModules['ext.jsonConfig'] = array(
		'localBasePath' => __DIR__,
		'remoteExtPath' => 'JsonConfig',
		'styles' => array( 'modules/JsonConfig.css' ),
		'position' => 'top',
	);

	// @TODO: Handle 'AbortMove' hook to prevent pages from being moved outside of the configuration space

	$hook = 'JsonConfig\JCSingleton::';
	$wgHooks['ContentHandlerDefaultModelFor'][] = $hook . 'onContentHandlerDefaultModelFor';
	$wgHooks['ContentHandlerForModelID'][] = $hook . 'onContentHandlerForModelID';
	$wgHooks['CodeEditorGetPageLanguage'][] = $hook . 'onCodeEditorGetPageLanguage';
	$wgHooks['EditFilterMergedContent'][] = $hook . 'onEditFilterMergedContent';
	$wgHooks['BeforePageDisplay'][] = $hook . 'onBeforePageDisplay';
	$wgHooks['PageContentSaveComplete'][] = $hook . 'onPageContentSaveComplete';
	$wgHooks['userCan'][] = $hook . 'onUserCan';
};

// MWNamespace::getCanonicalNamespaces() might be called before our own extension is initialized
$wgHooks['CanonicalNamespaces'][] = function ( array &$namespaces ) {
	if ( jsonConfigIsStorage() ) {
		// Class loader will not be called until it gets here
		\JsonConfig\JCSingleton::onCanonicalNamespaces( $namespaces );
	}

	return true;
};
