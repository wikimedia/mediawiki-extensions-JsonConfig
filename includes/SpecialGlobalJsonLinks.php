<?php
/**
 * Special page to show global JSON Data:-related usage. Note this
 * may include non-Data: namespace pages that are used in complex
 * systems like chart rendering and filters.
 *
 * Based on GlobalUsage extension's SpecialGlobalUsage
 */

namespace JsonConfig;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MainConfigNames;
use MediaWiki\Navigation\PagerNavigationBuilder;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use SearchEngineFactory;

class SpecialGlobalJsonLinks extends SpecialPage {
	/**
	 * @var Title
	 */
	protected $target;

	public function __construct(
		private readonly GlobalJsonLinks $globalJsonLinks,
		private readonly SearchEngineFactory $searchEngineFactory,
	) {
		parent::__construct( 'GlobalJsonLinks' );
	}

	/**
	 * Entry point
	 * @param string $par
	 */
	public function execute( $par ) {
		$target = $par ?: $this->getRequest()->getVal( 'target' );

		// Note we want to handle all namespaces, because other types of pages will be
		// used in the data pipeline in the future as we add enhancements.
		$this->target = Title::newFromText( $target );

		$this->setHeaders();
		$this->getOutput()->addWikiMsg( 'jsonconfig-globaljsonlinks-header' );

		if ( !$this->globalJsonLinks->isActive() ) {
			$this->getOutput()->addWikiMsg( 'jsonconfig-globaljsonlinks-disabled' );
			return;
		} elseif ( !$this->globalJsonLinks->onSharedRepo() ) {
			$this->getOutput()->addWikiMsg( 'jsonconfig-globaljsonlinks-remote', $target );
			return;
		}

		if ( $this->target !== null ) {
			$this->getOutput()->addWikiMsg( 'jsonconfig-globaljsonlinks-header-target',
				$this->target->getPrefixedText() );
		}
		$this->showForm();

		if ( $this->target === null ) {
			$this->getOutput()->setPageTitleMsg( $this->msg( 'jsonconfig-globaljsonlinks' ) );
			return;
		}

		$this->getOutput()->setPageTitleMsg(
			$this->msg( 'jsonconfig-globaljsonlinks-for', $this->target->getPrefixedText() ) );

		$this->showResult();
	}

	/**
	 * Shows the search form
	 */
	private function showForm() {
		$out = $this->getOutput();
		$form = HTMLForm::factory(
			'codex',
			[
				[
					'section' => 'jsonconfig-globaljsonlinks-text',
					'type' => 'text',
					'default' => $this->target === null ? '' : $this->target->getPrefixedText(),
					'name' => 'target',
				],
			],
			$this->getContext()
		);
		$form->setMethod( 'get' );
		$form->setWrapperLegend( true );
		$form->setAction( $this->getConfig()->get( MainConfigNames::Script ) );
		$form->addHiddenField( 'title', $this->getPageTitle()->getPrefixedText() );
		$form->addHiddenField( 'limit', $this->getRequest()->getInt( 'limit', 50 ) );
		$form->setSubmitText( $this->msg( 'jsonconfig-globaljsonlinks-ok' )->text() );
		$form->prepareForm();
		$out->addHTML( $form->getHTML( Status::newGood() ) );
	}

	/**
	 * BUilds a query and executes it based on $this->getRequest()
	 */
	private function showResult() {
		$query = $this->globalJsonLinks->batchQuery( $this->target->getTitleValue() );
		$request = $this->getRequest();

		// Extract params from $request.
		if ( $request->getText( 'from' ) ) {
			$query->setOffset( $request->getText( 'from' ) );
		} elseif ( $request->getText( 'to' ) ) {
			$query->setOffset( $request->getText( 'to' ), true );
		}
		$query->setLimit( $request->getInt( 'limit', 50 ) );

		// Perform query
		$query->execute();

		// Don't show form element if there is no data
		if ( $query->count() == 0 ) {
			$this->getOutput()->addWikiMsg( 'jsonconfig-globaljsonlinks-no-results', $this->target->getPrefixedText() );
			return;
		}

		$navbar = $this->getNavBar( $query );
		$targetName = $this->target->getPrefixedText();
		$out = $this->getOutput();

		// Top navbar
		$out->addHtml( $navbar );

		$out->addHtml( '<div id="mw-globaljsonlinks-result">' );
		foreach ( $query->getSinglePageResult() as $wiki => $result ) {
			$out->addHtml(
				'<h2>' . $this->msg(
					'jsonconfig-globaljsonlinks-on-wiki',
					$targetName, WikiMap::getWikiName( $wiki ) )->parse()
					. "</h2><ul>\n" );
			foreach ( $result as $item ) {
				$out->addHtml( "\t<li>" . self::formatItem( $item ) . "</li>\n" );
			}
			$out->addHtml( "</ul>\n" );
		}
		$out->addHtml( '</div>' );

		// Bottom navbar
		$out->addHtml( $navbar );
	}

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

	/**
	 * Helper function to create the navbar
	 *
	 * @param GlobalJsonLinksQuery $query An executed GlobalUsageQuery object
	 * @return string Navbar HTML
	 */
	protected function getNavBar( $query ) {
		$target = $this->target->getPrefixedText();
		$limit = $query->getLimit();

		// Find out which strings are for the prev and which for the next links
		$offset = $query->getOffsetString();
		$continue = $query->getContinueString();
		if ( $query->isReversed() ) {
			$from = $offset;
			$to = $continue;
		} else {
			$from = $continue;
			$to = $offset;
		}

		// Fetch the title object
		$title = $this->getPageTitle();

		$navBuilder = new PagerNavigationBuilder( $this );
		$navBuilder
			->setPage( $title )
			->setPrevTooltipMsg( 'prevn-title' )
			->setNextTooltipMsg( 'nextn-title' )
			->setLimitTooltipMsg( 'shown-title' );

		// Default query for all links, including nulls to ensure consistent order of parameters.
		// 'from'/'to' parameters are overridden for the 'previous'/'next' links below.
		$q = [
			'target' => $target,
			'from' => $to,
			'to' => null,
			'limit' => (string)$limit,
		];
		$navBuilder->setLinkQuery( $q );

		// Make 'previous' link
		if ( $to ) {
			$q = [ 'from' => null, 'to' => $to ];
			$navBuilder->setPrevLinkQuery( $q );
		}
		// Make 'next' link
		if ( $from ) {
			$q = [ 'from' => $from, 'to' => null ];
			$navBuilder->setNextLinkQuery( $q );
		}
		// Make links to set number of items per page
		$navBuilder
			->setLimitLinkQueryParam( 'limit' )
			->setCurrentLimit( $limit );

		return $navBuilder->getHtml();
	}

	/**
	 * Return an array of subpages beginning with $search that this special page will accept.
	 *
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return (usually 10)
	 * @param int $offset Number of results to skip (usually 0)
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		if ( !$this->globalJsonLinks->onSharedRepo() ) {
			// Local files on non-shared wikis are not useful as suggestion
			return [];
		}
		if ( !$search ) {
			// No prefix suggestion outside of file namespace
			return [];
		}
		$title = Title::newFromText( $search );
		$searchEngine = $this->searchEngineFactory->create();
		$searchEngine->setLimitOffset( $limit, $offset );
		// Autocomplete subpage the same as a normal search, but just for (local) files
		$result = $searchEngine->defaultPrefixSearch( $search );

		return array_map( static function ( Title $t ) {
			// Remove namespace in search suggestion
			return $t->getPrefixedText();
		}, $result );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'media';
	}
}
