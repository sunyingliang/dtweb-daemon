<?php

// Check server api
if (php_sapi_name() != 'cli') {
    die('Must be run via cli');
}

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/config.php';

// Process
$start = time();

try {
    if (!defined('DONKEYTUBE_CONNECTION')) {
        DT\Common\IO::message('Connection information for {DONKEYTUBE_CONNECTION} is not setup correctly');
    }

    $urlValidator = new \DT\Daemon\UrlValidatorTest(DONKEYTUBE_CONNECTION);
    $urlValidator->validate();
} catch (\Exception $e) {
    \DT\Common\IO::message($e->getMessage(), null, true);
}

$end = time();
\DT\Common\IO::message('Total used secondes: ' . ($end - $start));
