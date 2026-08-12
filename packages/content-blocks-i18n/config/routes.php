<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Default mount: the package's own `/_content-blocks/i18n` prefix, matching the
 * core's `/_content-blocks`.
 *
 * The controllers declare paths *relative* to it, so a host that would rather
 * put these routes somewhere else — under its admin path, behind its firewall —
 * imports `routes/bare.php` instead and names its own prefix. Nothing downstream
 * cares: the workbench generates every URL its JavaScript calls with `path()`.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/routes/bare.php')->prefix('/_content-blocks/i18n');
};
