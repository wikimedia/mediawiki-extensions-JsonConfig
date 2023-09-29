<?php
namespace JsonConfig;

use ApiModuleManager;
use Html;
use MediaWiki\EditPage\EditPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MessageSpecifier;
use OutputPage;
use Status;
use User;

/**
 * Hook handlers for JsonConfig extension.
 *
 * @file
 * @ingroup Extensions
 * @ingroup JsonConfig
 * @license GPL-2.0-or-later
 */
class JCHooks {

	/**
	 * Only register NS_CONFIG if running on the MediaWiki instance which houses
	 * the JSON configs (i.e. META)
	 * @todo FIXME: Always return true
	 * @param array &$namespaces
	 * @return true|void
	 */
	public static function onCanonicalNamespaces( array &$namespaces ) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		JCSingleton::init();
		foreach ( JCSingleton::$namespaces as $ns => $name ) {
			if ( $name === false ) { // must be already declared
				if ( !array_key_exists( $ns, $namespaces ) ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs: Namespace $ns " .
						"has not been declared by core or other extensions" );
				}
			} elseif ( array_key_exists( $ns, $namespaces ) ) {
				wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs: Namespace $ns => '$name' " .
					"is already declared as '$namespaces[$ns]'" );
			} else {
				$key = array_search( $name, $namespaces );
				if ( $key !== false ) {
					wfLogWarning( "JsonConfig: Invalid \$wgJsonConfigs: Namespace $ns => '$name' " .
						"has identical name with the namespace #$key" );
				} else {
					$namespaces[$ns] = $name;
				}
			}
		}
	}

	/**
	 * Initialize state
	 * @param Title $title
	 * @param string &$modelId
	 * @return bool
	 */
	public static function onContentHandlerDefaultModelFor( $title, &$modelId ) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		$jct = JCSingleton::parseTitle( $title );
		if ( $jct ) {
			$modelId = $jct->getConfig()->model;
			return false;
		}
		return true;
	}

	/**
	 * Ensure that ContentHandler knows about our dynamic models (T259126)
	 * @param string[] &$models
	 * @return bool
	 */
	public static function onGetContentModels( array &$models ) {
		global $wgJsonConfigModels;
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		JCSingleton::init();
		// TODO: this is copied from onContentHandlerForModelID()
		$ourModels = array_replace_recursive(
			\ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigModels' ),
			$wgJsonConfigModels
		);
		$models = array_merge( $models, array_keys( $ourModels ) );
		return true;
	}

	/**
	 * Instantiate JCContentHandler if we can handle this modelId
	 * @param string $modelId
	 * @param \ContentHandler &$handler
	 * @return bool
	 */
	public static function onContentHandlerForModelID( $modelId, &$handler ) {
		global $wgJsonConfigModels;
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		JCSingleton::init();
		$models = array_replace_recursive(
			\ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigModels' ),
			$wgJsonConfigModels
		);
		if ( array_key_exists( $modelId, $models ) ) {
			// This is one of our model IDs
			$handler = new JCContentHandler( $modelId );
			return false;
		}
		return true;
	}

	/**
	 * AlternateEdit hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/AlternateEdit
	 * @param EditPage $editpage
	 */
	public static function onAlternateEdit( EditPage $editpage ) {
		if ( !self::jsonConfigIsStorage() ) {
			return;
		}
		$jct = JCSingleton::parseTitle( $editpage->getTitle() );
		if ( $jct ) {
			$editpage->contentFormat = JCContentHandler::CONTENT_FORMAT_JSON_PRETTY;
		}
	}

	/**
	 * @param EditPage $editPage
	 * @param OutputPage $output
	 * @return bool
	 */
	public static function onEditPage( EditPage $editPage, OutputPage $output ) {
		global $wgJsonConfigUseGUI;
		if (
			$wgJsonConfigUseGUI &&
			$editPage->getTitle()->getContentModel() === 'Tabular.JsonConfig'
		) {
			$output->addModules( 'ext.jsonConfig.edit' );
		}
		return true;
	}

	/**
	 * Declares JSON as the code editor language for Config: pages.
	 * This hook only runs if the CodeEditor extension is enabled.
	 * @param Title $title
	 * @param string &$lang Page language.
	 * @return bool
	 */
	public static function onCodeEditorGetPageLanguage( $title, &$lang ) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		// todo/fixme? We should probably add 'json' lang to only those pages that pass parseTitle()
		$handler = MediaWikiServices::getInstance()
			->getContentHandlerFactory()
			->getContentHandler( $title->getContentModel() );
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON || JCSingleton::parseTitle( $title ) ) {
			$lang = 'json';
		}
		return true;
	}

	/**
	 * Validates that the revised contents are valid JSON.
	 * If not valid, rejects edit with error message.
	 * @param \IContextSource $context
	 * @param JCContent $content
	 * @param \Status $status
	 * @param string $summary Edit summary provided for edit.
	 * @param \User $user
	 * @param bool $minoredit
	 * @return bool
	 */
	public static function onEditFilterMergedContent(
		/** @noinspection PhpUnusedParameterInspection */
		$context, $content, $status, $summary, $user, $minoredit
	) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		if ( $content instanceof JCContent ) {
			$status->merge( $content->getStatus() );
			if ( !$status->isGood() ) {
				// @todo Use $status->setOK() instead after this extension
				// do not support mediawiki version 1.36 and before
				$status->setResult( false, $status->getValue() ?: EditPage::AS_HOOK_ERROR_EXPECTED );
				return false;
			}
		}
		return true;
	}

	/**
	 * Get the license code for the title or false otherwise.
	 * license code is identifier from https://spdx.org/licenses/
	 *
	 * @param JCTitle $jct
	 * @return bool|string Returns licence code string, or false if license is unknown
	 */
	private static function getTitleLicenseCode( JCTitle $jct ) {
		$jctContent = JCSingleton::getContent( $jct );
		if ( $jctContent && $jctContent instanceof JCDataContent ) {
			$license = $jctContent->getLicenseObject();
			if ( $license ) {
				return $license['code'];
			}
		}
		return false;
	}

	/**
	 * Override a per-page specific edit page copyright warning
	 *
	 * @param Title $title
	 * @param string[] &$msg
	 *
	 * @return bool
	 */
	public static function onEditPageCopyrightWarning( $title, &$msg ) {
		if ( self::jsonConfigIsStorage() ) {
			$jct = JCSingleton::parseTitle( $title );
			if ( $jct ) {
				$code = self::getTitleLicenseCode( $jct );
				if ( $code ) {
					$msg = [ 'jsonconfig-license-copyrightwarning', $code ];
				} else {
					$requireLicense = $jct->getConfig()->license ?? false;
					// Check if page has license field to apply only if it is required
					// https://phabricator.wikimedia.org/T203173
					if ( $requireLicense ) {
						$msg = [ 'jsonconfig-license-copyrightwarning-license-unset' ];
					}
				}
				return false; // Do not allow any other hook handler to override this
			}
		}
		return true;
	}

	/**
	 * Display a page-specific edit notice
	 *
	 * @param Title $title
	 * @param int $oldid
	 * @param array &$notices
	 * @return bool
	 */
	public static function onTitleGetEditNotices( Title $title, $oldid, array &$notices ) {
		if ( self::jsonConfigIsStorage() ) {
			$jct = JCSingleton::parseTitle( $title );
			if ( $jct ) {
				$code = self::getTitleLicenseCode( $jct );
				if ( $code ) {
					$noticeText = wfMessage( 'jsonconfig-license-notice', $code )->parse();
					$iconCodes = '';
					if ( preg_match_all( "/[a-z][a-z0-9]+/i", $code, $subcodes ) ) {
						// Flip order due to dom ordering of the floating elements
						foreach ( array_reverse( $subcodes[0] ) as $c => $match ) {
							// Used classes:
							// * mw-jsonconfig-editnotice-icon-BY
							// * mw-jsonconfig-editnotice-icon-CC
							// * mw-jsonconfig-editnotice-icon-CC0
							// * mw-jsonconfig-editnotice-icon-ODbL
							// * mw-jsonconfig-editnotice-icon-SA
							$iconCodes .= Html::rawElement(
								'span', [ 'class' => 'mw-jsonconfig-editnotice-icon-' . $match ], ''
							);
						}
						$iconCodes = Html::rawElement(
							'div', [ 'class' => 'mw-jsonconfig-editnotice-icons' ], $iconCodes
						);
					}

					$noticeFooter = Html::rawElement(
						'div', [ 'class' => 'mw-jsonconfig-editnotice-footer' ], ''
					);

					$notices['jsonconfig'] = Html::rawElement(
						'div',
						[ 'class' => 'mw-jsonconfig-editnotice' ],
						$iconCodes . $noticeText . $noticeFooter
					);
				} else {
					// Check if page has license field to apply notice msgs only when license is required
					// https://phabricator.wikimedia.org/T203173
					$requireLicense = $jct->getConfig()->license ?? false;
					if ( $requireLicense ) {
						$notices['jsonconfig'] = wfMessage( 'jsonconfig-license-notice-license-unset' )->parse();
					}
				}
			}
		}
		return true;
	}

	/**
	 * Override with per-page specific copyright message
	 *
	 * @param Title $title
	 * @param string $type
	 * @param string &$msg
	 * @param string &$link
	 *
	 * @return bool
	 */
	public static function onSkinCopyrightFooter( $title, $type, &$msg, &$link ) {
		if ( self::jsonConfigIsStorage() ) {
			$jct = JCSingleton::parseTitle( $title );
			if ( $jct ) {
				$code = self::getTitleLicenseCode( $jct );
				if ( $code ) {
					$msg = 'jsonconfig-license';
					$link = Html::element( 'a', [
						'href' => wfMessage( 'jsonconfig-license-url-' . $code )->plain()
					], wfMessage( 'jsonconfig-license-name-' . $code )->plain() );
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Adds CSS for pretty-printing configuration on NS_CONFIG pages.
	 * @param \OutputPage &$out
	 * @param \Skin &$skin
	 * @return bool
	 */
	public static function onBeforePageDisplay(
		/** @noinspection PhpUnusedParameterInspection */ &$out, &$skin
	) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		$title = $out->getTitle();
		// todo/fixme? We should probably add ext.jsonConfig style to only those pages
		// that pass parseTitle()
		$handler = MediaWikiServices::getInstance()
			->getContentHandlerFactory()
			->getContentHandler( $title->getContentModel() );
		if ( $handler->getDefaultFormat() === CONTENT_FORMAT_JSON ||
			JCSingleton::parseTitle( $title )
		) {
			$out->addModuleStyles( 'ext.jsonConfig' );
		}
		return true;
	}

	public static function onMovePageIsValidMove(
		Title $oldTitle, Title $newTitle, Status $status
	) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		$jctOld = JCSingleton::parseTitle( $oldTitle );
		if ( $jctOld ) {
			$jctNew = JCSingleton::parseTitle( $newTitle );
			if ( !$jctNew ) {
				$status->fatal( 'jsonconfig-move-aborted-ns' );
				return false;
			} elseif ( $jctOld->getConfig()->model !== $jctNew->getConfig()->model ) {
				$status->fatal( 'jsonconfig-move-aborted-model', $jctOld->getConfig()->model,
					$jctNew->getConfig()->model );
				return false;
			}
		}

		return true;
	}

	/**
	 * Conditionally load API module 'jsondata' depending on whether or not
	 * this wiki stores any jsonconfig data
	 *
	 * @param ApiModuleManager $moduleManager Module manager instance
	 * @return bool
	 */
	public static function onApiMainModuleManager( ApiModuleManager $moduleManager ) {
		global $wgJsonConfigEnableLuaSupport;
		if ( $wgJsonConfigEnableLuaSupport ) {
			$moduleManager->addModule( 'jsondata', 'action', JCDataApi::class );
		}
		return true;
	}

	public static function onPageSaveComplete(
		/** @noinspection PhpUnusedParameterInspection */
		\WikiPage $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	) {
		return self::onArticleChangeComplete( $wikiPage );
	}

	public static function onArticleDeleteComplete(
		/** @noinspection PhpUnusedParameterInspection */
		$article, &$user, $reason, $id, $content, $logEntry
	) {
		return self::onArticleChangeComplete( $article );
	}

	public static function onArticleUndelete(
		/** @noinspection PhpUnusedParameterInspection */
		$title, $created, $comment, $oldPageId
	) {
		return self::onArticleChangeComplete( $title );
	}

	public static function onPageMoveComplete(
		/** @noinspection PhpUnusedParameterInspection */
		$title, $newTitle, $user, $pageid, $redirid, $reason, $revisionRecord
	) {
		$title = Title::newFromLinkTarget( $title );
		$newTitle = Title::newFromLinkTarget( $newTitle );
		return self::onArticleChangeComplete( $title ) ||
			self::onArticleChangeComplete( $newTitle );
	}

	/**
	 * Prohibit creation of the pages that are part of our namespaces but have not been explicitly
	 * allowed.
	 * @param Title &$title
	 * @param User &$user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result
	 * @return bool
	 */
	public static function onGetUserPermissionsErrors(
		/** @noinspection PhpUnusedParameterInspection */
		&$title, &$user, $action, &$result
	) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		if ( $action === 'create' && JCSingleton::parseTitle( $title ) === null ) {
			// prohibit creation of the pages for the namespace that we handle,
			// if the title is not matching declared rules
			$result = 'jsonconfig-blocked-page-creation';
			return false;
		}
		return true;
	}

	/**
	 * @param \WikiPage|Title $value
	 * @param JCContent|null $content
	 * @return bool
	 */
	private static function onArticleChangeComplete( $value, $content = null ) {
		if ( !self::jsonConfigIsStorage() ) {
			return true;
		}

		if ( $value && ( !$content || $content instanceof JCContent ) ) {
			if ( method_exists( $value, 'getTitle' ) ) {
				$value = $value->getTitle();
			}
			$jct = JCSingleton::parseTitle( $value );
			if ( $jct && $jct->getConfig()->store ) {
				$store = new JCCache( $jct, $content );
				$store->resetCache();

				// Handle remote site notification
				$store = $jct->getConfig()->store;
				// @phan-suppress-next-line PhanTypeExpectedObjectPropAccess
				if ( $store->notifyUrl ) {
					$req =
						// @phan-suppress-next-line PhanTypeExpectedObjectPropAccess
						JCUtils::initApiRequestObj( $store->notifyUrl, $store->notifyUsername,
							// @phan-suppress-next-line PhanTypeExpectedObjectPropAccess
							$store->notifyPassword );
					if ( $req ) {
						$query = [
							'format' => 'json',
							'action' => 'jsonconfig',
							'command' => 'reload',
							'title' => $jct->getNamespace() . ':' . $jct->getDBkey(),
						];
						JCUtils::callApi( $req, $query, 'notify remote JsonConfig client' );
					}
				}
			}
		}
		return true;
	}

	/**
	 * Quick check if the current wiki will store any configurations.
	 * Faster than doing a full parsing of the $wgJsonConfigs in the JCSingleton::init()
	 * @return bool
	 */
	private static function jsonConfigIsStorage() {
		static $isStorage = null;
		if ( $isStorage === null ) {
			global $wgJsonConfigs;
			$isStorage = false;
			$configs = array_replace_recursive(
				\ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigs' ),
				$wgJsonConfigs
			);
			foreach ( $configs as $jc ) {
				if ( ( !array_key_exists( 'isLocal', $jc ) || $jc['isLocal'] ) ||
					( array_key_exists( 'store', $jc ) )
				) {
					$isStorage = true;
					break;
				}
			}
		}
		return $isStorage;
	}
}
