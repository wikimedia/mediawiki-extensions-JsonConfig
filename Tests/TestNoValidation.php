<?php
if ( !defined( 'MEDIAWIKI' ) ) {
	die( -1 );
}

$wgJsonConfigModels['Test.NoValidation'] = null;
$wgJsonConfigs['Test.NoValidation'] = array(
	'name' => 'NoValidation',
	'isLocal' => true,
);
