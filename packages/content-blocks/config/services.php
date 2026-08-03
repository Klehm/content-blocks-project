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
use ContentBlocks\Transfer\ContentAreaExporter;
use ContentBlocks\Transfer\ContentAreaExporterInterface;
use ContentBlocks\Transfer\ContentAreaImporter;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Section\SectionClonerInterface;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiatorInterface;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\Versioning\ContentVersionUpgraderInterface;
use ContentBlocks\Versioning\DenyOnMismatchUpgrader;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;
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
        // Schema generation of the host's block data; normally fed by the
        // bundle's semantic config (`content_blocks.content_version`). Stamped
        // onto content as it is written so hosts can target what predates a
        // change of their own making.
        ->set('content_blocks.content_version', 1)
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
        // Host-owned schema generation of block data: consumed by the exporter
        // (stamps the payload) and by SectionTemplateController (stamps a
        // snapshot). ContentAreaTouchListener takes it positionally.
        ->bind('int $contentVersion', '%content_blocks.content_version%')
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
    // `content_blocks.upload.directory` config (→ LocalFileStorage) or alias
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
    // Rendering override seam: host decorates/replaces via the interface.
    $services->alias(\ContentBlocks\Rendering\BlockRendererInterface::class, \ContentBlocks\Rendering\BlockRenderer::class);

    // Draft lifecycle, clone, transfer and section-template services. Each is
    // registered as its concrete class and aliased to its interface: consumers
    // type-hint the interface, so a host overrides or decorates any of them
    // without touching the package.
    $services->set(\ContentBlocks\Publishing\ContentAreaPublisher::class);
    $services->alias(ContentAreaPublisherInterface::class, \ContentBlocks\Publishing\ContentAreaPublisher::class);

    $services->set(SectionCloner::class);
    $services->alias(SectionClonerInterface::class, SectionCloner::class);

    $services->set(ContentAreaExporter::class);
    $services->alias(ContentAreaExporterInterface::class, ContentAreaExporter::class);
    $services->set(ContentAreaImporter::class);
    $services->alias(ContentAreaImporterInterface::class, ContentAreaImporter::class);

    // Section-template library: snapshot a section, re-insert it anywhere.
    // Saving/inserting is gated by AccessCheckerInterface on the area at hand;
    // managing the shared library (rename/delete) has no area to key off, so it
    // gets its own capability — deny by default, hosts alias their own.
    $services->set(SectionTemplateSerializer::class);
    $services->alias(SectionTemplateSerializerInterface::class, SectionTemplateSerializer::class);
    $services->set(SectionTemplateInstantiator::class);
    $services->alias(SectionTemplateInstantiatorInterface::class, SectionTemplateInstantiator::class);
    $services->set(DenyAllSectionTemplateManager::class);
    $services->alias(SectionTemplateManagerInterface::class, DenyAllSectionTemplateManager::class);

    // Content-version seam: what to do with stored content from another schema
    // generation of the *host's* own making. The package cannot know what
    // changed between two host versions, so the default refuses a known
    // mismatch (and accepts null, which only means "predates versioning").
    // Hosts alias this to migrate on read, or to be stricter.
    $services->set(DenyOnMismatchUpgrader::class);
    $services->alias(ContentVersionUpgraderInterface::class, DenyOnMismatchUpgrader::class);

    // Envelope upgrade chain: the *package's* side of versioning, migrating a
    // stored payload's structure forward when this package changes it. Ships
    // empty — only one format of each kind exists so far — but must exist
    // before the first bump, since the alternative is condemning every stored
    // payload. Steps are autoconfigured via EnvelopeUpgraderInterface.
    $services->set(EnvelopeUpgradeChain::class)
        ->args([tagged_iterator('content_blocks.envelope_upgrader')]);

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
        ->args(['%content_blocks.content_version%'])
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

    // ---------- Block data resolution (render payload) ----------

    // Seeds the payload from the entity's draft/published slots. Tagged by
    // hand — with a priority, so it runs ahead of anything a host registers —
    // hence autoconfigure(false): autoconfiguration would add the same tag a
    // second time and the resolver would run twice.
    $services->set(\ContentBlocks\Rendering\CoreBlockDataResolver::class)
        ->autoconfigure(false)
        ->tag('content_blocks.block_data_resolver', ['priority' => 256]);

    $services->set(\ContentBlocks\Rendering\BlockDataResolverCollection::class)
        ->args([tagged_iterator('content_blocks.block_data_resolver')])
        ->public();

    // "Which keys can this block type hold?" — shared by the two restore paths
    // (section-template insert, area import) so the union rule it encodes lives
    // in exactly one place.
    $services->set(\ContentBlocks\Block\BlockDataKeys::class);

    // Form types + the per-block form-extension collection. The collection's
    // `$extensions` argument (the [extension, target block type ids] pairs) is
    // populated by BlockFormExtensionPass; empty until a host tags an extension.
    // The attribute is a marker class read by reflection, never instantiated by
    // the container, so it is excluded from the glob — registering it would put
    // a meaningless service in every host's container (and would hard-fail the
    // day its constructor arguments stop having defaults).
    $services->load('ContentBlocks\\Form\\', '../src/Form/')
        ->exclude('../src/Form/Extension/AsBlockFormExtension.php');

    $services->load('ContentBlocks\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');
};
