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
    // The public asset routes live outside src/Controller/, so no `exclude`
    // is needed: symfony/routing 6.4.0 ignores it on a directory import.
    $routes->import(__DIR__ . '/../../src/Controller/', 'attribute');
};
