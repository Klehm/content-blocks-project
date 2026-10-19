<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server (`php -S … public/router.php`).
 *
 * The built-in server 404s any missing URL that ends in an extension instead of
 * reaching the front controller, and a Glide variant URL
 * (`/img/blocks/<hash>.png?w=400…`) looks exactly like one. nginx and apache
 * need none of this. Same file as the main sandbox's, for LiipImagine.
 */
if (\PHP_SAPI !== 'cli-server') {
    return false;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file(__DIR__ . urldecode($path))) {
    return false;
}

// Request base-path detection reads SCRIPT_FILENAME.
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';

// symfony/runtime reads the entry script's return value.
return require __DIR__ . '/index.php';
