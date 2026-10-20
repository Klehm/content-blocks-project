<?php

declare(strict_types=1);

use ContentBlocks\Media\Asset\LibraryAssetReferenceProvider;
use ContentBlocks\Media\Builder\MediaShellExtension;
use ContentBlocks\Media\Command\ImportCommand;
use ContentBlocks\Media\Controller\AssetController;
use ContentBlocks\Media\Controller\MediaController;
use ContentBlocks\Media\Library\MediaBrowser;
use ContentBlocks\Media\Library\MediaLibrary;
use ContentBlocks\Media\Storage\MediaRows;
use ContentBlocks\Media\Storage\RecordingFileStorage;
use ContentBlocks\Media\Storage\UploadRecorder;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\MediaLibraryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('content_blocks_media.kinds', ['image', 'video']);

    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->set(MediaRows::class);
    $services->set(UploadRecorder::class);

    // Whatever storage the host aliased: every stored file gets its row.
    // See docs/internals/media.md#the-decorator-keeps-the-inventory
    $services->set('content_blocks_media.recording_storage', FileStorageInterface::class)
        ->decorate(FileStorageInterface::class)
        ->factory([RecordingFileStorage::class, 'decorate'])
        ->args([service('.inner'), service(UploadRecorder::class)]);

    $services->set(MediaLibrary::class)
        ->args([param('content_blocks_media.kinds')]);
    $services->alias(MediaLibraryInterface::class, MediaLibrary::class);

    $services->set(LibraryAssetReferenceProvider::class);

    $services->set(MediaBrowser::class);
    $services->set(MediaShellExtension::class)
        ->args([param('content_blocks_media.kinds')]);

    // Controllers are not autoconfigured, or `routing.controllers` mounts them
    // unprefixed. @see docs/internals/bundle-boot.md
    $services->set(MediaController::class)->autoconfigure(false)
        ->tag('controller.service_arguments');
    $services->set(AssetController::class)->autoconfigure(false)
        ->tag('controller.service_arguments');

    $services->set(ImportCommand::class)
        ->arg('$referenceProviders', tagged_iterator('content_blocks.asset_reference_provider'));
};
