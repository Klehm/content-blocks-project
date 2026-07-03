<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Form types are always available; block services are registered
    // conditionally by ContentBlocksKitBundle::loadExtension() so a
    // disabled block never reaches the registry.
    $services->load('ContentBlocks\\Kit\\Form\\', '../src/Form/');

    // Twig extensions (e.g. cb_embed_url).
    $services->load('ContentBlocks\\Kit\\Twig\\', '../src/Twig/');

    // Controllers (the public kit.css endpoint).
    $services->load('ContentBlocks\\Kit\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');

    // File storage, the upload endpoint and the asset resolver bridge all
    // live in the main package now (ContentBlocks\Storage\*,
    // ContentBlocks\Controller\UploadController) — configure them via the
    // `content_blocks.upload` bundle config.
};
