<?php

namespace JsonConfig;

use MediaWiki\WikiMap\WikiMap;

class GlobalLinkItemFormatter {

	/**
	 * Helper to format a specific item
	 * @param array $item
	 * @return string
	 */
	public static function formatItem( $item ) {
		if ( $item['namespaceText'] == '' ) {
			$page = '';
		} else {
			$page = $item['namespaceText'] . ':';
		}
		$page .= $item['title']->getDbKey();

		$link = WikiMap::makeForeignLink(
			$item['wiki'], $page,
			str_replace( '_', ' ', $page )
		);
		// Return only the title if no link can be constructed
		return $link === false ? htmlspecialchars( $page ) : $link;
	}
}
