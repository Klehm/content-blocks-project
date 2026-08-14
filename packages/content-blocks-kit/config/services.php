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
    // conditionally by ContentBlocksKitBundle::loadExtension() so a
    // disabled block never reaches the registry.
    $services->load('ContentBlocks\\Kit\\Form\\', '../src/Form/');

    // Twig extensions (e.g. cb_embed_url).
    $services->load('ContentBlocks\\Kit\\Twig\\', '../src/Twig/');

    // Console commands (e.g. content-blocks-kit:blocks); AsCommand +
    // autoconfigure tags them as console.command.
    $services->load('ContentBlocks\\Kit\\Command\\', '../src/Command/');

    // Controllers (the public kit.css endpoint).
    $services->load('ContentBlocks\\Kit\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');

    // Rich-text editor adapters. Shipped implementations are registered by
    // the glob; a host's own is auto-tagged through
    // RichTextEditorInterface (see ContentBlocksKitBundle::build()). The
    // view value object is excluded — it is data, not a service.
    // `assets.packages` is what turns an `asset:<path>` option into a URL. It
    // only exists when the host enabled framework.assets, so it is passed
    // ignore-on-invalid rather than autowired: an editor is perfectly usable
    // without it, and the adapter reports the gap only if an `asset:` value
    // actually shows up.
    $services->load('ContentBlocks\\Kit\\RichText\\', '../src/RichText/')
        ->exclude('../src/RichText/RichTextEditorView.php')
        ->bind('$assets', service('assets.packages')->ignoreOnInvalid());

    $services->set(RichTextEditorRegistry::class)
        ->args([tagged_iterator('content_blocks_kit.rich_text_editor')]);

    // The shipped IconSet plus any host IconProviderInterface (auto-tagged in
    // ContentBlocksKitBundle::build()). One resolved set feeds both the icon
    // block's picker and cb_kit_icon(), so a pickable name is always drawable.
    $services->set(IconRegistry::class)
        ->args([tagged_iterator('content_blocks_kit.icon_provider')]);

    // File storage, the upload endpoint and the asset resolver bridge all
    // live in the main package now (ContentBlocks\Storage\*,
    // ContentBlocks\Controller\UploadController) — configure them via the
    // `content_blocks.upload` bundle config.
};
