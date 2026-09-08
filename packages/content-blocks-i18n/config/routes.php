<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Default mount, matching the core's prefix. A host that wants its own imports
 * `routes/bare.php` instead.
 *
 * @see docs/internals/i18n.md#config-and-mounting
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/routes/bare.php')->prefix('/_content-blocks/i18n');
};
