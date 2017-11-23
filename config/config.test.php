<?php

// Database configuration
define('DONKEYTUBE_CONNECTION', [
    'dns'       => 'mysql:host=mysql.donkeytube.co;dbname=donkeytube',
    'username'  => 'admin',
    'password'  => 'firesoft7102'
]);

define('LOG_DIR', __DIR__ . '/../log/');

// Definition of merging categories
define('MERGING_CATEGORY', [
    ['merge' => 333, 'to' => 222]
]);
