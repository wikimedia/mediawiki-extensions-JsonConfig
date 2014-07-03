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
		return $this->renderValue( $content, $content->getData(), array() );
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
	 * @param JCContent $content
	 * @param mixed $data
	 * @param array $path path to this field
	 * @return string: HTML.
	 */
	public function renderValue( JCContent $content, $data, array $path ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			$rows = array();
			$level = count( $path );
			foreach ( $data as $k => $v ) {
				$path[$level] = $k;
				$rows[] = $this->renderTableRow( $content, $v, $path );
			}
			if ( $rows ) {
				$res =
					Html::rawElement( 'table', array( 'class' => 'mw-jsonconfig' ),
						Html::rawElement( 'tbody', null, join( "\n", $rows ) ) );
			} else {
				$res = '';
			}
		} else {
			if ( is_string( $data ) ) {
				$res = $data;
			} else {
				$res = FormatJson::encode( $data );
			}
			$res = htmlspecialchars( $res );
		}

		return $res;
	}

	/**
	 * Convert $data into a table row, returning <tr>...</tr> element.
	 * @param JCContent $content
	 * @param mixed $data - treats it as opaque - renderValue will know how to handle it
	 * @param array $path path to this field
	 * @return string
	 */
	public function renderTableRow( JCContent $content, $data, array $path ) {
		$content = $this->renderRowContent( $content, $data, $path );
		return Html::rawElement( 'tr', null, $content );
	}

	/**
	 * Converts $data into the content of the <tr>...</tr> tag.
	 * By default returns <th> with the last path element and <td> with the renderValue() result.
	 * @param JCContent $content
	 * @param mixed $data - treats it as opaque - renderValue will know how to handle it
	 * @param array $path
	 * @return string
	 */
	public function renderRowContent( JCContent $content, $data, array $path ) {
		$key = end( $path );
		$th = is_string( $key ) ? Html::element( 'th', null, $key ) : '';

		$tdVal = $this->renderValue( $content, $data, $path );
		// If html begins with a '<', its a complex object, and should not have a class
		$attribs = null;
		if ( substr( $tdVal, 0, 1 ) !== '<' ) {
			$attribs = array( 'class' => 'mw-jsonconfig-value' );
		}
		$td = Html::rawElement( 'td', $attribs, $tdVal );

		return $th . $td;
	}
}
