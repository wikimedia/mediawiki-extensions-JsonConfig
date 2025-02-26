<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace JsonConfig;

use Article;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Page\Hook\ArticleViewFooterHook;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\NamespaceInfo;
use WikiMap;

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

		$title = $article->getTitle()->getTitleValue();
		if ( $title->getNamespace() != NS_DATA ) {
			return true;
		}

		$self = [];
		$others = [];
		$results = $this->globalJsonLinks->getLinksToTarget( $title );
		$currentWiki = WikiMap::getCurrentWikiId();
		$canonicalNamespaces = $this->namespaceInfo->getCanonicalNamespaces();
		foreach ( $results as $item ) {
			$wiki = $item['wiki'];
			$namespace = $item['namespace'];
			$namespaceText = $item['namespaceText']
				?? $canonicalNamespaces[$namespace]
					?? strval( $namespace );
			$title = $namespaceText;
			if ( $title !== '' ) {
				$title .= ':';
			}
			$title .= $item['title'];
			if ( $wiki === $currentWiki ) {
				$self[] = $title;
			} else {
				$others[$wiki][] = $title;
			}
		}

		$context = $article->getContext();

		if ( count( $self ) ) {
			$wikiName = WikiMap::getWikiName( $currentWiki );
			$html = '<h2 id="localjsonlinks">'
				. $context->msg( 'jsonconfig-localjsonlinks', $wikiName )->escaped() . "</h2>\n"
				. '<div id="mw-datapage-section-localjsonlinks">'
				. $context->msg( 'jsonconfig-localjsonlinks-of-page' )->parseAsBlock()
				. "<ul>\n"
				. $this->usageTrackingFooterForWiki( $context, $currentWiki, $self )
				. "</ul>\n"
				. "</div>";
			$context->getOutput()->addHtml( $html );
		}

		if ( count( $others ) ) {
			$html = '<h2 id="globaljsonlinks">'
				. $context->msg( 'jsonconfig-globaljsonlinks' )->escaped() . "</h2>\n"
				. '<div id="mw-datapage-section-globaljsonlinks">'
				. $context->msg( 'jsonconfig-globaljsonlinks-of-page' )->parseAsBlock()
				. "<ul>\n";
			foreach ( $others as $wiki => $titles ) {
				$html .= $this->usageTrackingFooterForWiki( $context, $wiki, $titles );
			}
			$html .= "</ul>\n"
				. "</div>";
			$context->getOutput()->addHtml( $html );
		}
		return true;
	}

	/**
	 * Convert list of remote titles to a nice bit of HTML.
	 * @param IContextSource $context
	 * @param string $wiki
	 * @param string[] $titles
	 * @return string $html
	 */
	private function usageTrackingFooterForWiki( IContextSource $context, string $wiki, array $titles ): string {
		$wikiName = WikiMap::getWikiName( $wiki );
		$escWikiName = Sanitizer::escapeClass( $wikiName );
		$html = '';
		$html .= "<li class='mw-gjl-onwiki-$escWikiName'>"
			. $context->msg( 'jsonconfig-globaljsonlinks-on-wiki', $wikiName )->parse()
			. "\n<ul>";
		foreach ( $titles as $title ) {
			$html .= "\t<li>" . $this->formatForeignLink( $wiki, $title ) . "</li>\n";
		}
		$html .= "</ul></li>\n";
		return $html;
	}

	/**
	 * @param string $wiki
	 * @param string $title
	 * @return string HTML link
	 */
	private function formatForeignLink( string $wiki, string $title ): string {
		$link = WikiMap::makeForeignLink(
			$wiki, $title,
			str_replace( '_', ' ', $title )
		);
		// Return only the title if no link can be constructed
		return $link === false ? htmlspecialchars( $title ) : $link;
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
