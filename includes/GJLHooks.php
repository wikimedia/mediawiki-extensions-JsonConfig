<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace JsonConfig;

use MediaWiki\Config\Config;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Page\Article;
use MediaWiki\Page\Hook\ArticleViewFooterHook;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/**
 * Hook handlers for JsonConfig extension.
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @license GPL-2.0-or-later
 */
class GJLHooks implements
	ArticleViewFooterHook,
	LinksUpdateCompleteHook
{
	/**
	 * Maximum number of global usage links to get in a single query.
	 */
	private const GLOBAL_JSONLINKS_QUERY_LIMIT = 50;

	/** @var GlobalJsonLinksQuery[] */
	private static $queryCache = [];

	private Config $config;
	private NamespaceInfo $namespaceInfo;
	private GlobalJsonLinks $globalJsonLinks;

	public function __construct(
		Config $config,
		NamespaceInfo $namespaceInfo,
		GlobalJsonLinks $globalJsonLinks
	) {
		$this->config = $config;
		$this->namespaceInfo = $namespaceInfo;
		$this->globalJsonLinks = $globalJsonLinks;
	}

	/**
	 * Get an executed query for use on json data pages
	 *
	 * @param Title $title Json data page to query for
	 * @return GlobalJsonLinksQuery Query object, already executed
	 */
	private function getDataPageQuery( Title $title ): GlobalJsonLinksQuery {
		$name = $title->getDBkey();
		if ( !isset( self::$queryCache[$name] ) ) {
			$query = $this->globalJsonLinks->batchQuery( $title->getTitleValue() );
			$query->setLimit( self::GLOBAL_JSONLINKS_QUERY_LIMIT );
			$query->execute();

			self::$queryCache[$name] = $query;

			// Limit cache size to 100
			if ( count( self::$queryCache ) > self::GLOBAL_JSONLINKS_QUERY_LIMIT ) {
				array_shift( self::$queryCache );
			}
		}

		return self::$queryCache[$name];
	}

	/**
	 * Add globaljsonlinks usage info for NS_DATA pages.
	 * @param Article $article
	 * @param bool $patrolFooterShown
	 * @return bool
	 */
	public function onArticleViewFooter( $article, $patrolFooterShown ) {
		if ( !JCHooks::jsonConfigIsStorage( $this->config ) ) {
			return true;
		}

		if ( !$this->config->get( 'TrackGlobalJsonLinks' ) ) {
			return true;
		}

		$title = $article->getTitle();
		if ( $title->getNamespace() != NS_DATA ) {
			return true;
		}

		$context = $article->getContext();
		$targetName = $title->getPrefixedText();
		$query = $this->getDataPageQuery( $title );

		$guHtml = '';
		foreach ( $query->getSinglePageResult() as $wiki => $result ) {
			$wikiName = WikiMap::getWikiName( $wiki );
			$escWikiName = Sanitizer::escapeClass( $wikiName );
			$guHtml .= "<li class='mw-gjl-onwiki-$escWikiName'>" . $context->msg(
				'jsonconfig-globaljsonlinks-on-wiki',
				$targetName, $wikiName )->parse() . "\n<ul>";
			foreach ( $result as $item ) {
				$guHtml .= "\t<li>" . GlobalLinkItemFormatter::formatItem( $item ) . "</li>\n";
			}
			$guHtml .= "</ul></li>\n";
		}

		if ( $guHtml ) {
			$html = '<h2 id="globaljsonlinks">' . $context->msg( 'jsonconfig-globaljsonlinks' )->escaped() . "</h2>\n"
				. '<div id="mw-datapage-section-globaljsonlinks">'
				. $context->msg( 'jsonconfig-globaljsonlinks-of-page' )->parseAsBlock()
				. "<ul>\n" . $guHtml . "</ul>\n";

			if ( $query->hasMore() ) {
				$html .= $context->msg( 'jsonconfig-globaljsonlinks-additional' )->parseAsBlock();
			}
			$html .= '</div>';
			$context->getOutput()->addHtml( $html );
		}

		return true;
	}

	/**
	 * Hook to LinksUpdateComplete
	 * Deletes old links from usage table and insert new ones.
	 * @param LinksUpdate $linksUpdater
	 * @param mixed $ticket Token returned by {@see IConnectionProvider::getEmptyTransactionTicket()}
	 */
	public function onLinksUpdateComplete( $linksUpdater, $ticket ) {
		// Track JSON usages in our custom shareable table
		$title = $linksUpdater->getTitle()->getTitleValue();
		$pages = array_keys(
			$linksUpdater->getParserOutput()->getExtensionData( GlobalJsonLinks::KEY_JSONLINKS ) ?? []
		);
		$this->globalJsonLinks->updateLinks( $title, $pages, $ticket );
	}
}
