<?php

namespace JsonConfig;

use MediaWiki\Html\Html;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageReference;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use stdClass;

/**
 * This class is used in case when there is no custom view defined for JCContent object
 */
class JCDefaultObjContentView extends JCDefaultContentView {

	/**
	 * Render JCContent object as HTML
	 * Called from an override of AbstractContent::fillParserOutput()
	 *
	 * Render JCContent object as HTML - replaces valueToHtml()
	 * @param JCContent|JCObjContent $content
	 * @param PageReference $page Context title for parsing
	 * @param int|null $revId Revision ID (for {{REVISIONID}})
	 * @param ParserOptions $options Parser options
	 * @param bool $generateHtml Whether or not to generate HTML
	 * @param ParserOutput &$output The output object to fill (reference).
	 * @return string
	 */
	public function valueToHtml(
		JCContent $content, PageReference $page, $revId, ParserOptions $options, $generateHtml,
		ParserOutput &$output
	): string {
		return $this->renderValue( $content, $content->getValidationData(), [] );
	}

	/**
	 * Constructs an HTML representation of a JSON object.
	 * @param JCObjContent|JCContent $content
	 * @param mixed|JCValue $data
	 * @param array $path path to this field
	 * @return string HTML.
	 */
	public function renderValue( JCContent $content, $data, array $path ): string {
		if ( $data instanceof JCValue ) {
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
	public function renderTableRow( JCContent $content, $data, array $path ): string {
		$attribs = $data instanceof JCValue ? $this->getValueAttributes( $data ) : [];
		$content = $this->renderRowContent( $content, $data, $path );
		return Html::rawElement( 'tr', $attribs, $content );
	}

	/**
	 * Get CSS attributes appropriate for the status of the given data
	 * @param JCValue $jcv
	 * @internal param JCValue|mixed $data
	 * @return array|null
	 */
	public function getValueAttributes( JCValue $jcv ): ?array {
		if ( $jcv->error() ) {
			return [ 'class' => 'mw-jsonconfig-error' ];
		} elseif ( $jcv->sameAsDefault() ) {
			return [ 'class' => 'mw-jsonconfig-same' ];
		} elseif ( $jcv->defaultUsed() ) {
			return [ 'class' => 'mw-jsonconfig-default' ];
		} elseif ( $jcv->isUnchecked() ) {
			return [ 'class' => 'mw-jsonconfig-unknown' ];
		}
		return null;
	}

	/**
	 * Determine if data is a special container that needs to be rendered as a comma-separated list.
	 * By default,
	 * @param JCContent $content
	 * @param array|stdClass $data
	 * @param array $path
	 * @return bool
	 */
	public function isList(
		/** @noinspection PhpUnusedParameterInspection */
		JCContent $content, $data, array $path
	): bool {
		if ( !is_array( $data ) ) {
			return false;
		}
		/** @var JCValue|mixed $v */
		foreach ( $data as $k => $v ) {
			$vv = $v instanceof JCValue ? $v->getValue() : $v;
			if ( !is_int( $k ) || !( is_string( $vv ) || is_numeric( $vv ) ) ) {
				return false;
			}
		}
		return true;
	}
}
