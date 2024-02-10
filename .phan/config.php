<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// To migrate later
$cfg['suppress_issue_types'][] = 'MediaWikiNoBaseException';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/Kartographer',
		'../../extensions/Scribunto',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/Kartographer',
		'../../extensions/Scribunto',
	]
);

return $cfg;
