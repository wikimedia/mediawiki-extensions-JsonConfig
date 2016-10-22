<?php

namespace JsonConfig;

use Html;
use ParserOptions;
use ParserOutput;
use Title;

/**
 * This class is used in case when there is no custom view defined for JCContent object
 * @package JsonConfig
 */
class JCTabularContentView extends JCContentView {

	/**
	 * Render JCContent object as HTML
	 * Called from an override of AbstractContent::fillParserOutput()
	 *
	 * @param JCContent|JCTabularContent $content
	 * @param Title $title Context title for parsing
	 * @param int|null $revId Revision ID (for {{REVISIONID}})
	 * @param ParserOptions $options Parser options
	 * @param bool $generateHtml Whether or not to generate HTML
	 * @param ParserOutput &$output The output object to fill (reference).
	 * @return string
	 */
	public function valueToHtml( JCContent $content, Title $title, $revId, ParserOptions $options,
								 $generateHtml, ParserOutput &$output ) {

		// Use user's language, and split parser cache.  This should not have a big
		// impact because data namespace is rarely viewed, but viewing it localized
		// will be valuable
		$lang = $options->getUserLangObj();
		$infoClass = [ 'class' => 'mw-jsonconfig-value-info' ];

		list( $data, $dataAttrs ) = self::split(
			$content->getValidationData(), 'mw-jsonconfig sortable' );

		if ( property_exists( $data, 'headers' ) ) {
			list( $headers, $headersAttrs ) = self::split( $data->headers );

			$titles = [ ];
			$titlesAttrs = '';
			if ( property_exists( $data, 'titles' ) ) {
				list( $titlesVals, $titlesAttrs ) = self::split( $data->titles );
				if ( !$titlesAttrs ) {
					$titles = $titlesVals;
				}
			}

			$vals = [ ];
			$types = [ ];
			if ( property_exists( $data, 'types' ) ) {
				$tmp = self::split( $data->types )[0];
				if ( is_array( $tmp ) ) {
					$types = array_map( function ( $v ) {
						/** @var JCValue|mixed $v */
						if ( is_a( $v, '\JsonConfig\JCValue' ) ) {
							return $v->error() ? '' : $v->getValue();
						}
						return $v;
					}, $tmp );
				}
			}

			$index = 0;
			foreach ( $headers as $colHeader ) {
				list( $colHeader, $columnAttrs ) = self::split( $colHeader );
				if ( !empty( $titles[$index] ) ) {
					list( $colTitle, $colTitleAttrs ) = self::split( $titles[$index] );
					if ( $colTitleAttrs ) {
						$colTitle = $colHeader;
					} else {
						$colTitle = JCUtils::pickLocalizedString( $colTitle, $lang );
					}
				} else {
					$colTitle = $colHeader;
					$colTitleAttrs = '';
				}

				$type = !empty( $types[$index] ) ? $types[$index] : 'invalid';
				$typeClass = $infoClass;
				$typeClass['title'] = wfMessage( 'jsonconfig-type-name-' . $type )->plain();
				$typeAbbr = wfMessage( 'jsonconfig-type-abbr-' . $type )->plain();

				$colTitle = htmlspecialchars( $colTitle ) .
						  Html::element( 'span', $typeClass, $typeAbbr );
				$vals[] = Html::rawElement( 'th', $columnAttrs ?: $colTitleAttrs, $colTitle );
				$index++;
			}
			$result = [ Html::rawElement( 'tr', $headersAttrs ?: $titlesAttrs, implode( '', $vals ) ) ];
		} else {
			$result = [];
		}

		if ( property_exists( $data, 'rows' ) ) {
			$rows = self::split( $data->rows )[0];
			foreach ( $rows as $row ) {
				list( $row, $rowAttrs ) = self::split( $row );
				$vals = [ ];
				foreach ( $row as $column ) {
					list( $column, $columnAttrs ) = self::split( $column, 'mw-jsonconfig-value' );
					if ( is_object( $column ) ) {
						$valueSize = count( (array)$column );
						$column = htmlspecialchars( JCUtils::pickLocalizedString( $column, $lang ) ) .
								  Html::element( 'span', $infoClass, "($valueSize)" );
						$vals[] = Html::rawElement( 'td', $columnAttrs, $column );
					} else {
						if ( is_bool( $column ) ) {
							$column = $column ? '☑' : '☐';
						}
						// TODO: We should probably introduce one CSS class per type
						$vals[] = Html::element( 'td', $columnAttrs, $column );
					}
				}
				$result[] = Html::rawElement( 'tr', $rowAttrs, implode( '', $vals ) );
			}
		}

		$html =
			$content->renderInfo( $lang ) .
			Html::rawElement( 'table', $dataAttrs,
				Html::rawElement( 'tbody', null, implode( "\n", $result ) ) ) .
			$content->renderLicense();

		return $html;
	}

	/**
	 * Converts JCValue into a raw data + class string. In case of an error, adds error class
	 * @param JCValue|mixed $data
	 * @param string $class
	 * @return array
	 */
	private static function split( $data, $class = '' ) {
		if ( $data instanceof JCValue ) {
			if ( $data->error() ) {
				if ( $class ) {
					$class .= ' ';
				}
				$class .= 'mw-jsonconfig-error';
			}
			$data = $data->getValue();
		}
		return [ $data, $class ? [ 'class' => $class ] : null ];
	}

	/**
	 * Returns default content for this object
	 * @param string $modelId
	 * @return string
	 */
	public function getDefault( $modelId ) {
		return <<<EOT
{
    // All comments will be automatically deleted on save

    // Mandatory "license" field. Only CC-0 (public domain dedication) is supported.
    "license": "CC0-1.0",

    // Mandatory list of headers. Each header must be a valid identifier with consisting of A..Z, a..z, 0..9, and _
    "headers": ["header1","header2" ],
    
    // Optional localized description of each column 
    "titles": [
        {"en": "header 1"},
        {"en": "header 2"}
    ],
    
    // Optional column types. Allowed values are number, string, boolean, and localized. Uses string by default. 
    "types": ["number", "string" ],
    
    // array of rows, with each row being an array of values
    "rows": [
        [ 42, "peace" ]
    ]
}
EOT;

	}
}
