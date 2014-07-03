<?php

namespace JsonConfig;

use Html;

/**
 * This class is used in case when there is no custom view defined for JCContent object
 * @package JsonConfig
 */
class JCDefaultObjContentView extends JCDefaultContentView {

	/**
	 * Render JCContent object as HTML
	 * @param JCContent|JCObjContent $content
	 * @return string
	 */
	public function valueToHtml( JCContent $content ) {
		return $this->renderValue( $content, $content->getValidationData(), array() );
	}

	/**
	 * Constructs an HTML representation of a JSON object.
	 * @param JCObjContent|JCContent $content
	 * @param mixed|JCValue $data
	 * @param array $path path to this field
	 * @return string: HTML.
	 */
	public function renderValue( JCContent $content, $data, array $path ) {
		$value = is_a( $data, '\JsonConfig\JCValue' ) ? $data->getValue() : $data;
		return parent::renderValue( $content, $value, $path );
	}

	/**
	 * Convert array's key-value pair into a string of <tr><th>...</th><td>...</td></tr> elements
	 * @param JCObjContent|JCContent $content
	 * @param mixed|JCValue $data
	 * @param array $path path to this field
	 * @return string
	 */
	public function renderTableRow( JCContent $content, $data, array $path ) {
		$attribs = $this->getValueAttributes( $data );
		$content = $this->renderRowContent( $content, $data, $path );
		return Html::rawElement( 'tr', $attribs, $content );
	}

	/**
	 * Get CSS attributes appropriate for the status of the given data
	 * @param JCValue|mixed $data
	 * @return array|null
	 */
	public function getValueAttributes( $data ) {
		$jcv = is_a( $data, '\JsonConfig\JCValue' ) ? $data : null;
		if ( $jcv ) {
			$attribs = null;
			if ( $jcv->error() ) {
				$attribs = 'mw-jsonconfig-error';
			} elseif ( $jcv->sameAsDefault() ) {
				$attribs = 'mw-jsonconfig-same';
			} elseif ( $jcv->defaultUsed() ) {
				$attribs = 'mw-jsonconfig-default';
			} elseif ( $jcv->isUnchecked() ) {
				$attribs = 'mw-jsonconfig-unknown';
			} else {
				return null;
			}
			return array( 'class' => $attribs );
		}
		return null;
	}
}
