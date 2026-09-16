<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The builder's endpoints with **no prefix of their own**, for a host mounting
 * them itself. The public asset routes are left out: see `public.php`.
 *
 * @see docs/guide/routing.md
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        __DIR__ . '/../../src/Controller/',
        'attribute',
        false,
        __DIR__ . '/../../src/Controller/AssetController.php',
    );
};
