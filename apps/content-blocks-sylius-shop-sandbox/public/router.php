<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server (`php -S … public/router.php`).
 *
 * The built-in server only falls back to the front controller for URLs that do
 * not look like a file. Anything ending in an extension is treated as a static
 * asset and 404s when it is missing — which is exactly what LiipImagine's lazy
 * cache URL looks like (`/media/cache/resolve/cb_w400/uploads/…/photo.png`), so
 * no variant would ever be generated. nginx and apache route everything to
 * index.php and need none of this.
 *
 * Existing files are still served directly (`return false`).
 */
if (\PHP_SAPI !== 'cli-server') {
    return false;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file(__DIR__ . urldecode($path))) {
    return false;
}

// Keep the front controller as the script, not this router: request base-path
// detection reads SCRIPT_FILENAME.
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';

// `return`, not a bare require: symfony/runtime reads the entry script's return
// value, and index.php hands back the application callable. The previous version
// of this file required it without returning, which made the runtime throw —
// nothing noticed, because nothing ran through the router until now.
return require __DIR__ . '/index.php';
