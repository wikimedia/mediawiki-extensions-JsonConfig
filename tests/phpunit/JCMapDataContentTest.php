<?php

namespace JsonConfig\Tests;

use JsonConfig\JCMapDataContent;
use LogicException;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @group JsonConfig
 * @covers \JsonConfig\JCMapDataContent
 * @group Database
 */
class JCMapDataContentTest extends MediaWikiIntegrationTestCase {
	private const CONTENT_STUB = '{
			"description": {
				"en": "[[Do not parse]]"
			},
			"license": "CC0-1.0",
			"zoom": 0,
			"latitude": 0,
			"longitude": 0
		}';

	protected function setUp(): void {
		$this->overrideConfigValue( 'KartographerMapServer', 'https://maps.wikimedia.org' );
	}

	/**
	 * @dataProvider provideGetSafeData
	 * @param string $input
	 * @param string $expected
	 */
	public function testGetSafeData( $input, $expected ) {
		$this->overrideConfigValues( [
			MainConfigNames::ScriptPath => '/w',
			MainConfigNames::Script => '/w/index.php',
		] );

		$data = json_decode( self::CONTENT_STUB );
		$data->data = json_decode( $input );

		$content = new JCMapDataContent( json_encode( $data ), 'some model', true );
		$localized = $content->getLocalizedData(
			$this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' )
		);
		$sanitized = json_encode( $content->getSafeData( $localized )->data, JSON_PRETTY_PRINT );
		$expected = json_encode( json_decode( $expected ), JSON_PRETTY_PRINT );

		if ( !$content->isValid() ) {
			throw new LogicException( html_entity_decode( $content->getStatus()->getWikiText() ) );
		}

		$this->assertSame( $expected, $sanitized );
	}

	public static function provideGetSafeData() {
		return [
			[
				'{
					"type": "Point",
					"coordinates": [ 10, 20 ],
					"properties": {
						"title": "[[link]]",
						"description": "<img src=x onerror=alert(1)> \'\'\'Bold\'\'\'"
					}
				}',
				'{
					"type": "Point",
					"coordinates": [ 10, 20 ],
					"properties": {
						"title": "<a href=\"\/w\/index.php?title=Link&amp;action=edit&amp;redlink=1\" class=\"new\"'
							. ' title=\"Link (page does not exist)\">link<\/a>",
						"description": "&lt;img src=x onerror=alert(1)&gt; <b>Bold<\/b>"
					}
				}',
			],
			[
				'{
					"type": "Point",
					"coordinates": [ 10, 20 ],
					"properties": {
						"title": {
							"en": "[[link]]"
						},
						"description": {
							"ru": "Unexpected",
							"en": "<img src=x onerror=alert(1)> \'\'\'Bold\'\'\'"
						}
					}
				}',
				'{
					"type": "Point",
					"coordinates": [ 10, 20 ],
					"properties": {
						"title": "<a href=\"\/w\/index.php?title=Link&amp;action=edit&amp;redlink=1\" class=\"new\"'
							. ' title=\"Link (page does not exist)\">link<\/a>",
						"description": "&lt;img src=x onerror=alert(1)&gt; <b>Bold<\/b>"
					}
				}',
			],
			[
				'{
					"type": "GeometryCollection",
					"geometries": [
						{
							"type": "Point",
							"coordinates": [ 10, 20 ],
							"properties": {
								"title": "[[link]]",
								"description": "<img src=x onerror=alert(1)> \'\'\'Bold\'\'\'"
							}
						},
						{
							"type": "Point",
							"coordinates": [ 30, 40 ],
							"properties": {
								"title": {
									"en": "[[link]]"
								},
								"description": {
									"ru": "Unexpected",
									"en": "<img src=x onerror=alert(1)> \'\'\'Bold\'\'\'"
								}
							}
						}
					]
				}',
				'{
					"type": "GeometryCollection",
					"geometries": [
						{
							"type": "Point",
							"coordinates": [ 10, 20 ],
							"properties": {
								"title": "<a href=\"\/w\/index.php?title=Link&amp;action=edit&amp;redlink=1\"'
									. ' class=\"new\" title=\"Link (page does not exist)\">link<\/a>",
								"description": "&lt;img src=x onerror=alert(1)&gt; <b>Bold<\/b>"
							}
						},
						{
							"type": "Point",
							"coordinates": [ 30, 40 ],
							"properties": {
								"title": "<a href=\"\/w\/index.php?title=Link&amp;action=edit&amp;redlink=1\"'
									. ' class=\"new\" title=\"Link (page does not exist)\">link<\/a>",
								"description": "&lt;img src=x onerror=alert(1)&gt; <b>Bold<\/b>"
							}
						}
					]
				}',
			],
			[
				'{
					"type": "ExternalData",
					"service": "geoshape",
					"should not": "be here",
					"ids": 123,
					"url": "http://potentially.malicious"
				}',
				'{
					"type": "ExternalData",
					"service": "geoshape",
					"url": "https:\/\/maps.wikimedia.org\/geoshape?getgeojson=1&ids=123"
				}'
			],
		];
	}
}
