<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Default mount. A host choosing its own imports `routes/editor.php` and
 * `routes/public.php` instead; route names are identical either way.
 *
 * @see docs/guide/routing.md
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/routes/editor.php')->prefix('/_content-blocks');
    $routes->import(__DIR__ . '/routes/public.php')->prefix('/_content-blocks/public');
};
