<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The routes with no prefix of their own, for a host choosing its mount
 * point. Route names are identical either way.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../src/Controller/', 'attribute');
};
