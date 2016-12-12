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

		$okClass = [ ];
		$infoClass = [ 'class' => 'mw-jsonconfig-value-info' ];
		$errorClass = [ 'class' => 'mw-jsonconfig-error' ];
		$result = [ ];

		$dataAttrs = [ 'class' => 'mw-jsonconfig sortable' ];
		if ( !$content->getValidationData() || $content->getValidationData()->error() ) {
			$dataAttrs['class'] .= ' mw-jsonconfig-error';
		}
		$flds = $content->getField( [ 'schema', 'fields' ] );
		if ( $flds && !$flds->error() ) {
			$vals = [ ];
			foreach ( $flds->getValue() as $fld ) {
				$name = $content->getField( 'name', $fld );
				$type = $content->getField( 'type', $fld );
				$label = $content->getField( 'title', $fld );

				if ( $name && !$name->error() && $type && !$type->error() &&
					 ( !$label || !$label->error() )
				) {
					$labelAttrs = $okClass;
				} else {
					$labelAttrs = $errorClass;
				}

				if ( $label && !$label->error() ) {
					$label = JCUtils::pickLocalizedString( $label->getValue(), $lang );
				} elseif ( $name && !$name->error() ) {
					$label = $name->getValue();
				} else {
					$label = '';
				}

				$type = !$type || $type->error() ? 'invalid' : $type->getValue();
				$typeAttrs = $infoClass;
				$typeAttrs['title'] = wfMessage( 'jsonconfig-type-name-' . $type )->plain();
				$typeAbbr = wfMessage( 'jsonconfig-type-abbr-' . $type )->plain();

				$label = htmlspecialchars( $label ) .
						  Html::element( 'span', $typeAttrs, $typeAbbr );
				$vals[] = Html::rawElement( 'th', $labelAttrs, $label );
			}
			$result[] = Html::rawElement( 'tr', $okClass, implode( '', $vals ) );
		}

		$data = $content->getField( 'data' );
		if ( $data && !$data->error() ) {
			foreach ( $data->getValue() as $row ) {
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
						} elseif ( $column === null ) {
							// TODO: Should we append the CSS class instead?
							$columnAttrs['class'] = 'mw-jsonconfig-value-null';
							$column = '';
						}
						// TODO: We should probably introduce one CSS class per type
						$vals[] = Html::element( 'td', $columnAttrs, $column );
					}
				}
				$result[] = Html::rawElement( 'tr', $rowAttrs, implode( '', $vals ) );
			}
		}

		global $wgParser;

		$html =
			$content->renderDescription( $lang ) .
			Html::rawElement( 'table', $dataAttrs,
				Html::rawElement( 'tbody', null, implode( "\n", $result ) ) ) .
			$content->renderSources( $wgParser->getFreshParser(), $title, $revId, $options ) .
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
    // !!!!! All comments will be automatically deleted on save !!!!!

    // Optional "description" field to describe this data
    "description": {"en": "table description"},

    // Optional "sources" field to describe the sources of the data.  Can use Wiki Markup
    "sources": "Copied from [http://example.com Example Data Source]",

    // Mandatory "license" field. Only CC-0 (public domain dedication) is supported.
    "license": "CC0-1.0+",

    // Mandatory fields schema. Each field must be an object with
    //   "name" being a valid identifier with consisting of letters, digits, and "_"
    //   "type" being one of the allowed types like "number", "string", "boolean", "localized"
    "schema": {
        "fields": [
            {
                "name": "header1",
                "type": "number",
                // Optional label for this field
                "title": {"en": "header 1"},
            },
            {
                "name": "header2",
                "type": "string",
                // Optional label for this field
                "title": {"en": "header 2"},
            }
        ]
    },

    // array of data, with each row being an array of values
    "data": [
        [ 42, "peace" ]
    ]
}
EOT;

	}
}
