<?php

// Router script for PHP built-in server with Symfony Runtime

if (php_sapi_name() !== 'cli-server') {
    return false;
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly
if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

// Forward to Symfony front controller
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
