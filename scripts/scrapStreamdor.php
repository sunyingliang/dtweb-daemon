<?php

// Check server api
if (php_sapi_name() != 'cli') {
    die('Must be run via cli');
}

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/config.php';

// Process
\DT\Common\IO::message('Start scraping steamdor.com...');

try {
    if (!defined('DONKEYTUBE_CONNECTION')) {
        DT\Common\IO::message('Connection information for {DONKEYTUBE_CONNECTION} is not setup correctly');
    }

    $streamdorScraper = new \DT\Daemon\StreamdorScraper(DONKEYTUBE_CONNECTION);
    $streamdorScraper->scrap();
} catch (\Exception $e) {
    \DT\Common\IO::message($e->getMessage(), null, true);
}
