<?php

namespace JsonConfig;

use FormatJson;
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
		if ( is_a( $data, '\JsonConfig\JCValue' ) ) {
			$value = $data->getValue();
			if ( !is_array( $value ) && !is_object( $value ) ) {
				$attribs = $this->getValueAttributes( $data );
				if ( $attribs ) {
					return Html::element( 'span', $attribs,
						is_string( $value ) ? $value : FormatJson::encode( $value ) );
				}
			}
		} else {
			$value = $data;
		}
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
		$attribs = is_a( $data, '\JsonConfig\JCValue' ) ? $this->getValueAttributes( $data ) : null;
		$content = $this->renderRowContent( $content, $data, $path );
		return Html::rawElement( 'tr', $attribs, $content );
	}

	/**
	 * Get CSS attributes appropriate for the status of the given data
	 * @param JCValue $jcv
	 * @internal param JCValue|mixed $data
	 * @return array|null
	 */
	public function getValueAttributes( JCValue $jcv ) {
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

	/**
	 * Determine if data is a special container that needs to be rendered as a comma-separated list.
	 * By default,
	 * @param JCContent $content
	 * @param $data
	 * @param array $path
	 * @return bool
	 */
	public function isList(
		/** @noinspection PhpUnusedParameterInspection */
		JCContent $content, $data, array $path
	) {
		if ( !is_array( $data ) ) {
			return false;
		}
		/** @var JCValue|mixed $v */
		foreach ( $data as $k => $v ) {
			$vv = is_a( $v, '\JsonConfig\JCValue' ) ? $v->getValue() : $v;
			if ( !is_int( $k ) || !( is_string( $vv ) || is_numeric( $vv ) ) ) {
				return false;
			}
		}
		return true;
	}
}
