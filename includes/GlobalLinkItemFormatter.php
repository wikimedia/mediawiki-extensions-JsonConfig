<?php

namespace MediaWiki\Extension\JsonConfig;

// phpcs:ignore MediaWiki.Classes.UnusedUseStatement
use MediaWiki\Title\TitleValue;
use MediaWiki\WikiMap\WikiMap;

class GlobalLinkItemFormatter {

	/**
	 * Helper to format a specific item
	 * @param array{wiki: string, namespaceText: string, title: TitleValue, target: TitleValue} $item
	 * @return string
	 */
	public static function formatItem( $item ) {
		if ( $item['namespaceText'] == '' ) {
			$page = '';
		} else {
			$page = $item['namespaceText'] . ':';
		}
		$page .= $item['title']->getDBkey();

		$link = WikiMap::makeForeignLink(
			$item['wiki'], $page,
			str_replace( '_', ' ', $page )
		);
		// Return only the title if no link can be constructed
		return $link === false ? htmlspecialchars( $page ) : $link;
	}
}
