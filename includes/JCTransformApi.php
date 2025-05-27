<?php
namespace JsonConfig;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Get JSON data transformed by a Lua script
 */
class JCTransformApi extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$source = $params['title'];

		$jct = JCSingleton::parseTitle( $source, NS_DATA );
		if ( !$jct ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $source ) ] );
		}

		$module = $params['jtmodule'];
		$function = $params['jtfunction'];
		$args = [];
		foreach ( $params['jtargs'] ?? [] as $item ) {
			$bits = explode( '=', $item, 2 );
			if ( count( $bits ) == 2 ) {
				$args[$bits[0]] = $bits[1];
			} else {
				$args[] = $bits[0];
			}
		}
		$transform = new JCTransform( $module, $function, $args );

		$loader = JCSingleton::getContentLoader( $jct );
		$loader->transform( $transform );
		$status = $loader->load();

		if ( !$status->isOk() ) {
			$this->dieStatus( $status );
		} else {
			$wrapper = $status->getValue();
			$content = $wrapper->getContent();
			$data = $content->getSafeData( $wrapper->getContent()->getData() );
			$expiry = $wrapper->getExpiry();
			$deps = $wrapper->getDependencies();
		}

		$data = [
			'data' => $data,
			'expiry' => $expiry,
			'dependencies' => array_map( static function ( $titleValue ) {
				return [
					'namespace' => $titleValue->getNamespace(),
					'title' => $titleValue->getDbKey(),
				];
			}, $deps )
		];

		// Armor any API metadata in $data
		$data = ApiResult::addMetadataToResultVars( $data );

		$this->getResult()->addValue( null, $this->getModuleName(), $data );

		$this->getMain()->setCacheMaxAge( $expiry ); // seconds
		$this->getMain()->setCacheMode( 'public' );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'jtmodule' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'jtfunction' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'jtargs' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ALLOW_DUPLICATES => true,
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=jsontransform&formatversion=2&format=jsonfm&title=Sample.tab' .
				'&jtmodule=Samples&jtfunction=round&jtargs=decimals=2|columns=a,b'
				=> 'apihelp-jsontransform-example-1',
		];
	}

	/** @inheritDoc */
	public function isInternal() {
		return true;
	}
}
