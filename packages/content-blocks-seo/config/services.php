<?php

declare(strict_types=1);

use ContentBlocks\Seo\Builder\SeoActionProvider;
use ContentBlocks\Seo\Builder\SeoShellExtension;
use ContentBlocks\Seo\Controller\AssetController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->set(SeoActionProvider::class);
    $services->set(SeoShellExtension::class)
        ->arg('$auditOptions', param('content_blocks_seo.audit_options'));

    // Controllers are not autoconfigured, or `routing.controllers` mounts them
    // unprefixed. @see docs/internals/bundle-boot.md
    $services->set(AssetController::class)->autoconfigure(false)
        ->tag('controller.service_arguments');
};
