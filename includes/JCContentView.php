<?php

namespace JsonConfig;

/**
 * Interface JCContentView is used as an optional override of the JCContent::valueToHtml()
 * To use it, set $wgJsonConfigModels[$modelId]['view'] = 'MyJCContentViewClass';
 * @package JsonConfig
 */
interface JCContentView {

	/**
	 * Render JCContent object as HTML
	 * @param JCContent $content
	 * @return string
	 */
	public function valueToHtml( JCContent $content );
}
