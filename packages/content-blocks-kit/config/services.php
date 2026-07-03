<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('ContentBlocks\\Kit\\Block\\', '../src/Block/');
    $services->load('ContentBlocks\\Kit\\Form\\', '../src/Form/');

    // File storage, the upload endpoint and the asset resolver bridge all
    // live in the main package now (ContentBlocks\Storage\*,
    // ContentBlocks\Controller\UploadController) — configure them via the
    // `content_blocks.upload` bundle config.
};
