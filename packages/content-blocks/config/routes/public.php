<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The CSS/JS a public page links to, with no prefix of their own. Keep them
 * outside any firewall: visitors load them too.
 *
 * @see docs/guide/routing.md
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../src/Controller/AssetController.php', 'attribute');
};
