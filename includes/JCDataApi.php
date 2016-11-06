<?php
namespace JsonConfig;

use ApiBase;
use ApiFormatJson;

/**
 * Get localized json data, similar to Lua's mw.data.get() function
 */
class JCDataApi extends ApiBase {

	public function execute() {
		$printerParams = $this->getMain()->getPrinter()->extractRequestParams();
		if ( !( $this->getMain()->getPrinter() instanceof ApiFormatJson ) ||
			 !isset( $printerParams['formatversion'] )
		) {
			$this->dieUsage( 'This module only supports format=json and format=jsonfm',
				'invalidparammix' );
		}
		if ( $printerParams['formatversion'] == 1 ) {
			$this->dieUsage( 'This module only supports formatversion=2 or later',
				'invalidparammix' );
		}

		$params = $this->extractRequestParams();
		$jct = JCSingleton::parseTitle( $params['title'], NS_DATA );
		if ( !$jct ) {
			$this->dieUsageMsg( [ 'invalidtitle', $params['title'] ] );
		}

		$data = JCSingleton::getContent( $jct );
		if ( !$data ) {
			$this->dieUsageMsg( [ 'invalidtitle', $jct ] );
		} elseif ( !method_exists( $data, 'getLocalizedData' ) ) {
			$data = $data->getData();
		} else {
			/** @var JCDataContent $data */
			$data = $data->getLocalizedData( $this->getLanguage() );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $data );

		$this->getMain()->setCacheMaxAge( 24 * 60 * 60 ); // seconds
		$this->getMain()->setCacheMode( 'public' );
	}

	public function getAllowedParams() {
		return [
			'title' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'api.php?action=jsondata&formatversion=2&format=jsonfm&title=Sample.tab'
				=> 'apihelp-jsondata-example-1',
			'api.php?action=jsondata&formatversion=2&format=jsonfm&title=Sample.tab&uselang=fr'
				=> 'apihelp-jsondata-example-2',
		];
	}

	public function isInternal() {
		return true;
	}
}
