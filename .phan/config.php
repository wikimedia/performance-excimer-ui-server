<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = [
	'src',
	'vendor',
];
$cfg['exclude_analysis_directory_list'][] = 'vendor';
$cfg['exception_classes_with_optional_throws_phpdoc'][] = \Wikimedia\ExcimerUI\Server\ServerError::class;

return $cfg;
