<?php

namespace JsonConfig;

/**
 * This class is used as a way to specify how to edit/view JCContent object
 * To use it, set $wgJsonConfigModels[$modelId]['view'] = 'MyJCContentViewClass';
 * @package JsonConfig
 */
abstract class JCContentView {

	/**
	 * Render JCContent object as HTML
	 * @param JCContent $content
	 * @return string
	 */
	abstract public function valueToHtml( JCContent $content );

	/**
	 * Returns default content for this object.
	 * The returned valued does not have to be valid JSON
	 * @param string $modelId
	 * @return string
	 */
	abstract public function getDefault( $modelId );
}
