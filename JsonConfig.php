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
	'license-name' => 'GPL-2.0+',
);

define( 'NS_CONFIG', 482 );
define( 'NS_CONFIG_TALK', 483 );

$cwd = __DIR__ . DIRECTORY_SEPARATOR;
$wgMessagesDirs['JsonConfig'] = $cwd . 'i18n';

// @todo: this entry should be done only if $wgJsonConfigEnabled === true && namespace is actually used by config
$wgExtensionMessagesFiles['JsonConfigNamespaces'] = $cwd . 'JsonConfig.namespaces.php';

$cwd .= 'includes' . DIRECTORY_SEPARATOR;
foreach ( array(
			'JCApi',
			'JCCache',
			'JCContent',
			'JCContentHandler',
			'JCContentView',
			'JCDefaultContentView',
			'JCDefaultObjContentView',
			'JCObjContent',
			'JCSingleton',
			'JCUtils',
			'JCValidators',
			'JCValue',
		) as $key => $class ) {
	$wgAutoloadClasses['JsonConfig\\' . ( is_string( $key ) ? $key : $class )] = $cwd . $class . '.php';
}

/**
 * Each extension should add its configuration profiles as described in the doc
 * https://www.mediawiki.org/wiki/Requests_for_comment/Json_Config_pages_in_wiki
 * https://www.mediawiki.org/wiki/Extension:JsonConfig
 */
$wgJsonConfigs = array();

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
 * Change this value whenever the entire JsonConfig cache needs to be invalidated
 */
$wgJsonConfigCacheKeyPrefix = '1';

/**
 * Quick check if the current wiki will store any configurations.
 * Faster than doing a full parsing of the $wgJsonConfigs in the JCSingleton::init()
 * @return bool
 */
function jsonConfigIsStorage() {
	static $isStorage = null;
	if ( $isStorage === null ) {
		global $wgJsonConfigs;
		$isStorage = false;
		foreach ( $wgJsonConfigs as $jc ) {
			if ( ( array_key_exists( 'isLocal', $jc ) && $jc['isLocal'] ) ||
			     ( array_key_exists( 'store', $jc ) )
			) {
				$isStorage = true;
				break;
			}
		}
	}

	return $isStorage;
}

// Registers hooks and resources which are only required on the config-hosting wiki.
$wgExtensionFunctions[] = function () {
	global $wgAPIModules;
	$wgAPIModules['jsonconfig'] = 'JsonConfig\JCApi';

	// The rest of the function is storage-related only
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

	$prefix = 'JsonConfig\JCSingleton::on';
	foreach ( array(
		          'ContentHandlerDefaultModelFor',
		          'ContentHandlerForModelID',
		          'CodeEditorGetPageLanguage',
		          'EditFilterMergedContent',
		          'BeforePageDisplay',
		          'MovePageIsValidMove',
		          'AbortMove',
		          'ArticleDeleteComplete',
		          'ArticleUndelete',
		          'PageContentSaveComplete',
		          'TitleMoveComplete',
		          'userCan',
	              'UnitTestsList'
	          ) as $hook ) {
		$wgHooks[$hook][] = $prefix . $hook;
	}
};

// MWNamespace::getCanonicalNamespaces() might be called before our own extension is initialized
$wgHooks['CanonicalNamespaces'][] = function ( array &$namespaces ) {
	if ( jsonConfigIsStorage() ) {
		// Class loader will not be called until it gets here
		\JsonConfig\JCSingleton::onCanonicalNamespaces( $namespaces );
	}

	return true;
};
