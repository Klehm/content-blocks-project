<?php

declare(strict_types=1);

use ContentBlocks\Kit\Storage\FileStorageInterface;
use ContentBlocks\Kit\Storage\NullFileStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('ContentBlocks\\Kit\\Block\\', '../src/Block/');
    $services->load('ContentBlocks\\Kit\\Form\\', '../src/Form/');
    $services->load('ContentBlocks\\Kit\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');

    // Default: null storage (no-op). Host app must override for file upload support.
    $services->set(NullFileStorage::class);
    $services->alias(FileStorageInterface::class, NullFileStorage::class);
};
