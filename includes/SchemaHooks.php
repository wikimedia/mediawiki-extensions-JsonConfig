<?php
/**
 * JsonConfig schema hooks for updating globaljsonlinks* tables.
 */

namespace JsonConfig;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * Hook to apply schema changes
	 *
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';

		$type = $updater->getDB()->getType();
		$updater->addExtensionTable( 'globaljsonlinks', "$dir/$type/tables-generated.sql" );
	}

}
