<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The package's routes with **no prefix of their own** — for a host that wants
 * to choose the mount point:
 *
 *     # config/routes/content_blocks_i18n.yaml
 *     content_blocks_i18n:
 *         resource: '@ContentBlocksI18nBundle/config/routes/bare.php'
 *         prefix: /admin/translations
 *
 * Importing `../routes.php` instead keeps the default `/_content-blocks/i18n`.
 * Route *names* are identical either way, which is what makes the choice
 * invisible to templates and to the workbench's own JavaScript.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../src/Controller/', 'attribute');
};
