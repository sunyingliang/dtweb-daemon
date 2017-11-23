<?php

// Check server api
if (php_sapi_name() != 'cli') {
    die('Must be run via cli');
}

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/config.php';

// Process
\DT\Common\IO::message('Start merging categories...');

// check MERGING_CATEGORY constant
if (!defined('MERGING_CATEGORY')) {
    \DT\Common\IO::message('Constant {MERGING_CATEGORY} is not defined correctly', null, true);
}

// Invoke category merging process
try {
    if (!defined('DONKEYTUBE_CONNECTION')) {
        DT\Common\IO::message('Connection information for {DONKEYTUBE_CONNECTION} is not setup correctly', null, true);
    }

    (new \DT\Daemon\CategoryMerging(DONKEYTUBE_CONNECTION))->merge();
} catch (\Exception $e) {
    \DT\Common\IO::message($e->getMessage(), null, true);
}
