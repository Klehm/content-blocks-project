<?php

declare(strict_types=1);

use ContentBlocks\Sylius\Tests\Integration\TestDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/sylius/test-application/config/bootstrap.php';

foreach (['BUNDLES_TO_ENABLE', 'CONFIGS_TO_IMPORT', 'ROUTES_TO_IMPORT'] as $name) {
    if (isset($_ENV[$name])) {
        $_SERVER[$name] = $_ENV[$name];
    }
}

TestDatabase::create();
