<?php
declare( strict_types = 1 );

namespace JsonConfig;

use MediaWiki\Config\Config;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Extension\CodeMirror\Hooks\CodeMirrorGetModeHook;
use MediaWiki\Title\Title;

/**
 * Loads CodeMirror as the editor for JsonConfig content models.
 */
class CodeMirrorHooks implements CodeMirrorGetModeHook {

	public function __construct(
		private readonly Config $config,
		private readonly IContentHandlerFactory $contentHandlerFactory
	) {
	}

	/**
	 * Declares JSON as the CodeMirror mode for Config: pages.
	 * This hook only runs if the CodeMirror extension is enabled.
	 * @param Title $title The title the mode is for
	 * @param string|null &$mode The mode to use
	 * @param string $model The content model of the title
	 * @return bool True to continue or false to abort
	 */
	public function onCodeMirrorGetMode( Title $title, ?string &$mode, string $model ): bool {
		if ( $this->config->get( 'JsonConfigUseCodeMirror' ) && JCHooks::jsonConfigIsStorage( $this->config ) ) {
			$handler = $this->contentHandlerFactory->getContentHandler( $title->getContentModel() );
			if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON && JCSingleton::parseTitle( $title ) ) {
				$mode = 'json';
				return false;
			}
		}

		return true;
	}
}
