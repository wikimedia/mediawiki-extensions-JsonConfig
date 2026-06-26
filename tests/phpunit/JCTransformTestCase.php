<?php

namespace MediaWiki\Extension\JsonConfig\Tests;

use MediaWiki\Extension\JsonConfig\JCSingleton;
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
		$this->markTestSkippedIfExtensionNotLoaded( 'Scribunto' );
		parent::setUp();
		$this->configureTransformTest();
	}

	public function addDBDataOnce() {
		// this is run before the parent::setUp() so need to check
		// for Scribunto here.
		$this->markTestSkippedIfExtensionNotLoaded( 'Scribunto' );
		$this->configureTransformTest();
		$this->editTransformPage( 'JCTransform_samples', 'lua', NS_MODULE );
		$this->editTransformPage( 'Sample_input.tab', 'json', NS_DATA );
		$this->editTransformPage( 'Second_input.tab', 'json', NS_DATA );
	}

	private function configureTransformTest(): void {
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
				'Tabular.JsonConfig' => 'MediaWiki\Extension\JsonConfig\JCTabularContent'
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
	}

	private function editTransformPage( string $pageName, string $extension, int $namespace ): void {
		$fileName = __DIR__ . "/transforms/$pageName.$extension";
		$content = file_get_contents( $fileName );
		$title = Title::makeTitle( $namespace, $pageName );
		$this->editPage( $title, $content );
	}

	protected function tearDown(): void {
		parent::tearDown();
		JCSingleton::init( /* force */ true );
	}

}
