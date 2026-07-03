<?php

declare(strict_types=1);

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Asset\NullAssetResolver;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Doctrine\ContentAreaTouchListener;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Preview\NullContentAreaUrlResolver;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Replace\DefaultContentAreaProvider;
use ContentBlocks\Section\BuiltInSectionDecorator;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\SectionTemplate\DenyAllSectionTemplateManager;
use ContentBlocks\SectionTemplate\SectionTemplateManagerInterface;
use ContentBlocks\Service\ContentAreaExporter;
use ContentBlocks\Service\ContentAreaImporter;
use ContentBlocks\Service\SectionCloner;
use ContentBlocks\Service\SectionTemplateInstantiator;
use ContentBlocks\Service\SectionTemplateSerializer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    // Section-level defaults. These parameters are shared by
    // BuiltInSectionDecorator, CoreSectionDefaults and SectionSettingsType
    // so a host can override them in one place and have the form pre-fill,
    // the placeholder, and the rendered fallback all move together.
    // `default_width_mode` is the width every new section starts in
    // ('full' or 'centered'); `default_max_width` is the cap applied once
    // a section is centered.
    $container->parameters()
        ->set('content_blocks.section.default_width_mode', 'full')
        ->set('content_blocks.section.default_max_width', 1320)
        // List of {label, color} entries; normally fed by the bundle's
        // semantic config (`content_blocks.palette`) via loadExtension().
        ->set('content_blocks.palette', [])
        // List of {name, label, css_class, settings} entries; normally fed
        // by the bundle's semantic config (`content_blocks.styles`).
        ->set('content_blocks.section_styles', []);

    // Upload endpoint limits; normally fed by the bundle's semantic config
    // (`content_blocks.upload.*`) via loadExtension().
    $container->parameters()
        ->set('content_blocks.upload.max_size', 10 * 1024 * 1024)
        ->set('content_blocks.upload.allowed_mime_types', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
        ]);

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        // Targeted bindings: only services whose constructor literally
        // declares `int $defaultMaxWidth` / `string $defaultWidthMode` pick
        // these up — currently BuiltInSectionDecorator, CoreSectionDefaults,
        // and SectionSettingsType.
        ->bind('int $defaultMaxWidth', '%content_blocks.section.default_max_width%')
        ->bind('string $defaultWidthMode', '%content_blocks.section.default_width_mode%')
        // Upload limits: consumed by UploadController.
        ->bind('int $uploadMaxSize', '%content_blocks.upload.max_size%')
        ->bind('array $uploadAllowedMimeTypes', '%content_blocks.upload.allowed_mime_types%');

    $services->set(BlockTypeRegistry::class)
        ->public();

    // Default: deny all access. Host app must override with its own implementation.
    $services->set(DenyAllAccessChecker::class);
    $services->alias(AccessCheckerInterface::class, DenyAllAccessChecker::class);

    // Default: throws on resolve. Host app must override with its own implementation.
    $services->set(NullContentAreaUrlResolver::class);
    $services->alias(ContentAreaUrlResolverInterface::class, NullContentAreaUrlResolver::class);

    // ---------- File storage / uploads ----------

    // Default: null storage (throws on upload). Hosts opt in via the
    // `content_blocks.upload.dir` config (→ LocalFileStorage) or alias
    // FileStorageInterface to their own implementation (S3, Flysystem…).
    $services->set(\ContentBlocks\Storage\NullFileStorage::class);
    $services->alias(\ContentBlocks\Storage\FileStorageInterface::class, \ContentBlocks\Storage\NullFileStorage::class);

    // Asset resolver bridges the export/import flow to FileStorageInterface.
    // With the default NullFileStorage behind it, exports see zero assets
    // and imports throw if the payload references any (the old
    // NullAssetResolver behavior).
    $services->set(NullAssetResolver::class);
    $services->set(\ContentBlocks\Asset\FileStorageAssetResolver::class);
    $services->alias(AssetResolverInterface::class, \ContentBlocks\Asset\FileStorageAssetResolver::class);

    $services->load('ContentBlocks\\Twig\\Component\\', '../src/Twig/Component/')
        ->tag('twig.component');

    $services->set(\ContentBlocks\Twig\ContentBlocksExtension::class)
        ->tag('twig.extension');

    $services->set(\ContentBlocks\Rendering\BlockRenderer::class);

    $services->set(\ContentBlocks\Service\ContentAreaPublisher::class);

    $services->set(SectionCloner::class);

    $services->set(ContentAreaExporter::class);
    $services->set(ContentAreaImporter::class);

    // Section-template library: snapshot a section, re-insert it anywhere.
    // Serializer + instantiator are plain services. Saving/inserting is gated
    // by AccessCheckerInterface on the area at hand; managing the shared
    // library (rename/delete) has no area to key off, so it gets its own
    // capability — deny by default, hosts alias their own implementation.
    $services->set(SectionTemplateSerializer::class);
    $services->set(SectionTemplateInstantiator::class);
    $services->set(DenyAllSectionTemplateManager::class);
    $services->alias(SectionTemplateManagerInterface::class, DenyAllSectionTemplateManager::class);

    // Replace flow: default provider is usable out of the box; hosts
    // override by aliasing ContentAreaProviderInterface to their own
    // implementation in services.yaml.
    $services->set(DefaultContentAreaProvider::class);
    $services->alias(ContentAreaProviderInterface::class, DefaultContentAreaProvider::class);

    // Doctrine onFlush listener that bubbles child writes up to
    // ContentArea::updatedAt. Tagged explicitly so the package doesn't
    // depend on DoctrineBundle's #[AsDoctrineListener] attribute at the
    // composer level (DoctrineBundle is a host concern).
    $services->set(ContentAreaTouchListener::class)
        ->tag('doctrine.event_listener', ['event' => 'onFlush']);

    // ---------- Section settings extension hooks ----------

    // Note: SectionStyleProviderInterface + SectionDecoratorInterface are
    // auto-tagged globally via registerForAutoconfiguration() in the
    // bundle's build(); host-app implementations don't need any explicit
    // tag.

    $services->set(SectionStyleRegistry::class)
        ->args([tagged_iterator('content_blocks.section_style_provider')])
        ->public();

    // Config-declared section style presets (`content_blocks.styles`).
    // Registered before any host provider, so a PHP provider re-using a
    // name wins in the registry (last provider wins per name).
    $services->set(\ContentBlocks\Section\ConfigSectionStyleProvider::class)
        ->args(['%content_blocks.section_styles%']);

    // ---------- Color palette ----------

    // Config-declared palette colors (`content_blocks.palette`). Hosts can
    // add more colors by implementing ColorPaletteProviderInterface
    // (auto-tagged, see ContentBlocksBundle::build()).
    $services->set(ConfigColorPaletteProvider::class)
        ->args(['%content_blocks.palette%']);

    $services->set(ColorPaletteRegistry::class)
        ->args([tagged_iterator('content_blocks.color_palette_provider')])
        ->public();

    // Built-in decorator runs first so host extensions can react to or
    // override its output via tag priority if needed.
    $services->set(BuiltInSectionDecorator::class);

    // Reads the `styling` sub-form from settings and emits CSS vars +
    // classes consumed by styling.css.
    $services->set(\ContentBlocks\Section\StylingSectionDecorator::class);

    // Pre-populates the styling sub-form with sane defaults — notably
    // `backgroundColor=#ffffff` to avoid the <input type="color"> black
    // default. Tagged via SectionSettingsDefaultsProviderInterface auto-
    // configuration.
    $services->set(\ContentBlocks\Section\CoreStylingDefaults::class);

    // Top-level section defaults (mirror of CoreStylingDefaults for the
    // root settings array). Currently provides `maxWidth` so a centered
    // section without an explicit value still picks a sensible cap.
    $services->set(\ContentBlocks\Section\CoreSectionDefaults::class);

    $services->set(SectionDecoratorCollection::class)
        ->args([tagged_iterator('content_blocks.section_decorator')])
        ->public();

    $services->set(SectionSettingsDefaults::class)
        ->args([tagged_iterator('content_blocks.section_settings_defaults')])
        ->public();

    // ---------- Block decoration ----------

    // Auto-configured: any class implementing BlockDecoratorInterface
    // is tagged `content_blocks.block_decorator` (see ContentBlocksBundle).
    $services->set(\ContentBlocks\Block\StylingBlockDecorator::class);

    $services->set(\ContentBlocks\Block\BlockDecoratorCollection::class)
        ->args([tagged_iterator('content_blocks.block_decorator')])
        ->public();

    // Pre-populates the block's styling sub-form with sane defaults —
    // notably `backgroundColor=#ffffff` (mirror of CoreStylingDefaults
    // on the section side). Tagged via BlockDataDefaultsProviderInterface
    // auto-configuration.
    $services->set(\ContentBlocks\Block\CoreBlockStylingDefaults::class);

    $services->set(\ContentBlocks\Block\BlockDataDefaults::class)
        ->args([tagged_iterator('content_blocks.block_data_defaults')])
        ->public();

    $services->load('ContentBlocks\\Form\\', '../src/Form/');

    $services->load('ContentBlocks\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');
};
