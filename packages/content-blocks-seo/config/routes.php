<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Default mount, beside the core's. A host that wants its own imports
 * `routes/bare.php` instead.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/routes/bare.php')->prefix('/_content-blocks/seo');
};
