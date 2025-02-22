<?php
namespace JsonConfig;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Get localized json data, similar to Lua's mw.data.get() function
 */
class JCDataApi extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$jct = JCSingleton::parseTitle( $params['title'], NS_DATA );
		if ( !$jct ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		$data = JCSingleton::getContent( $jct );
		if ( !$data ) {
			$this->dieWithError(
				[
					'apierror-invalidtitle',
					wfEscapeWikiText( Title::newFromLinkTarget( $jct )->getPrefixedText() )
				]
			);
		} elseif ( !method_exists( $data, 'getLocalizedData' ) ) {
			$data = $data->getData();
		} else {
			/** @var JCDataContent $data */
			'@phan-var JCDataContent $data';
			$data = $data->getSafeData( $data->getLocalizedData( $this->getLanguage() ) );
		}

		// Armor any API metadata in $data
		$data = ApiResult::addMetadataToResultVars( (array)$data, is_object( $data ) );

		$this->getResult()->addValue( null, $this->getModuleName(), $data );

		$this->getMain()->setCacheMaxAge( 24 * 60 * 60 ); // seconds
		$this->getMain()->setCacheMode( 'public' );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=jsondata&formatversion=2&format=jsonfm&title=Sample.tab'
				=> 'apihelp-jsondata-example-1',
			'action=jsondata&formatversion=2&format=jsonfm&title=Sample.tab&uselang=fr'
				=> 'apihelp-jsondata-example-2',
		];
	}

	/** @inheritDoc */
	public function isInternal() {
		return true;
	}
}
