<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// The kit ships one controller: the public stylesheet endpoint
// (AssetController). The upload endpoint moved to the main package.
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
