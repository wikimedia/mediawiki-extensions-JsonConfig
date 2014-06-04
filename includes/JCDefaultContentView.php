<?php

namespace JsonConfig;

use FormatJson;
use Html;

/**
 * This class is used in case when there is no custom view defined for JCContent object
 * @package JsonConfig
 */
class JCDefaultContentView extends JCContentView {

	/**
	 * Render JCContent object as HTML
	 * @param JCContent $content
	 * @return string
	 */
	public function valueToHtml( JCContent $content ) {
		return $this->toHtml( $content->getData() );
	}

	/**
	 * Returns default content for this object
	 * @param string $modelId
	 * @return string
	 */
	public function getDefault( $modelId ) {
		return "{\n}";
	}

	/**
	 * Constructs an HTML representation of a JSON object.
	 * @param mixed $value
	 * @param int|string|null $key
	 * @param int $level Tree level
	 * @return string: HTML.
	 */
	public function toHtml( $value, $key = null, $level = 0 ) {
		if ( is_array( $value ) && count( $value ) !== 0 ) {
			$rows = array();
			foreach ( $value as $k => $v ) {
				$rows[] = Html::rawElement( 'tr', null,
					$this->rowToHtml( $k, $v, $level + 1, $key, $value ) );
			}
			$res = Html::rawElement( 'table', array( 'class' => 'mw-jsonconfig' ),
				Html::rawElement( 'tbody', null, join( "\n", $rows ) ) );
		} elseif ( is_array( $value ) ) {
			$res = '';
		} elseif ( !is_string( $value ) ) {
			$res = FormatJson::encode( $value );
		} else {
			$res = $value;
		}

		return $res;
	}

	/**
	 * Convert array's key-value pair into a string of <th>...</th><td>...</td> elements
	 * @param int|string $key
	 * @param mixed $value
	 * @param int $level
	 * @param int|string $parentKey
	 * @param mixed $parentValue
	 * @return string
	 */
	public function rowToHtml( $key, $value, $level, $parentKey, $parentValue ) {
		if ( is_string( $key ) ) {
			$th = Html::element( 'th', null, $key );
		} else {
			$th = '';
		}

		$tdVal = $this->toHtml( $value, $key, $level );

		// If html begins with a '<', its a complex object, and should not have a class
		$attribs = null;
		if ( substr( $tdVal, 0, 1 ) !== '<' ) {
			$attribs = array( 'class' => 'mw-jsonconfig-value' );
		}
		$td = Html::rawElement( 'td', $attribs, $tdVal );

		return $th . $td;
	}
}
