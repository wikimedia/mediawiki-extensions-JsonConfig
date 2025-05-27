<?php

namespace JsonConfig\Tests;

use JsonConfig\JCSingleton;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \JsonConfig\JCTransformer
 * @group Database
 */
class JCTransformTestCase extends MediaWikiIntegrationTestCase {

	/**
	 * Load up the sample filter lua state
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'LanguageCode' => 'en',
			'JsonConfigEnableLuaSupport' => true,
			'JsonConfigTransformsEnabled' => true,
			'JsonConfigs' => [
				'Tabular.JsonConfig' => [
					'namespace' => 486,
					'nsName' => 'Data',
					'pattern' => '/.\.tab$/',
					'license' => 'CC0-1.0',
					'isLocal' => true,
					'store' => true,
				]
			],
			'JsonConfigModels' => [
				'Tabular.JsonConfig' => 'JsonConfig\JCTabularContent'
			],
		] );
		JCSingleton::init( /* force */ true );

		// Hack: it doesn't seem to initialize the Data: namespace from this
		// correctly for all parts of the wiki. Try adding it explicitly.
		$namespaces = $this->getServiceContainer()->getContentLanguage()->getNamespaces();
		if ( !array_key_exists( NS_DATA, $namespaces ) ) {
			$this->overrideConfigValue( 'ExtraNamespaces', [
				NS_DATA => 'Data',
				NS_DATA_TALK => 'Data_talk',
			] );
		}

		$moduleName = 'JCTransform_samples';
		$fileName = __DIR__ . "/transforms/$moduleName.lua";
		$content = file_get_contents( $fileName );
		$title = Title::makeTitle( NS_MODULE, $moduleName );
		$this->editPage( $title, $content );

		$tableName = 'Sample_input.tab';
		$fileName = __DIR__ . "/transforms/$tableName.json";
		$content = file_get_contents( $fileName );
		$title = Title::makeTitle( NS_DATA, $tableName );
		$this->editPage( $title, $content );

		$tableName = 'Second_input.tab';
		$fileName = __DIR__ . "/transforms/$tableName.json";
		$content = file_get_contents( $fileName );
		$title = Title::makeTitle( NS_DATA, $tableName );
		$this->editPage( $title, $content );
	}

	protected function tearDown(): void {
		parent::tearDown();
		JCSingleton::init( /* force */ true );
	}

}
