<?php

declare(strict_types=1);

use ContentBlocks\Kit\Icon\IconRegistry;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Form types are always available; block services are registered
    // conditionally, so a disabled block never reaches the registry.
    $services->load('ContentBlocks\\Kit\\Form\\', '../src/Form/');

    // Twig extensions (e.g. cb_embed_url).
    $services->load('ContentBlocks\\Kit\\Twig\\', '../src/Twig/');

    // Console commands (e.g. content-blocks-kit:blocks); AsCommand +
    // autoconfigure tags them as console.command.
    $services->load('ContentBlocks\\Kit\\Command\\', '../src/Command/');

    // Controllers (the public kit.css endpoint).
    $services->load('ContentBlocks\\Kit\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');

    // The view object is excluded: it is data, not a service.
    // `assets.packages` needs framework.assets, hence ignore-on-invalid.
    $services->load('ContentBlocks\\Kit\\RichText\\', '../src/RichText/')
        ->exclude('../src/RichText/RichTextEditorView.php')
        ->bind('$assets', service('assets.packages')->ignoreOnInvalid());

    $services->set(RichTextEditorRegistry::class)
        ->args([tagged_iterator('content_blocks_kit.rich_text_editor')]);

    // One resolved set feeds both the picker and cb_kit_icon().
    // See docs/internals/kit.md#icons-are-added-not-filtered
    $services->set(IconRegistry::class)
        ->args([tagged_iterator('content_blocks_kit.icon_provider')]);

    // File storage and the upload endpoint live in the core package now;
    // configure them through `content_blocks.upload`.
};
