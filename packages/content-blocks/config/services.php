<?php

declare(strict_types=1);

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Asset\NullAssetResolver;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Clipboard\BlockDataReplayer;
use ContentBlocks\Clipboard\BlockSnapshotSerializer;
use ContentBlocks\Clipboard\BlockSnapshotSerializerInterface;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Doctrine\ContentAreaTouchListener;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\ActionLogStoreInterface;
use ContentBlocks\History\BuilderSession;
use ContentBlocks\History\DoctrineActionLogStore;
use ContentBlocks\History\SidebarOutcome;
use ContentBlocks\History\StateApplier;
use ContentBlocks\Publishing\JournalPruningPublisher;
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
use ContentBlocks\SectionTemplate\SectionPosterBuilder;
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
    // Shared by the decorator, the defaults provider and the form type, so
    // one override moves the pre-fill, the placeholder and the fallback.
    $container->parameters()
        // See docs/internals/versioning.md#the-content-version
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
        // Targeted: only a constructor literally declaring these names
        // picks them up.
        ->bind('int $defaultMaxWidth', '%content_blocks.section.default_max_width%')
        ->bind('string $defaultWidthMode', '%content_blocks.section.default_width_mode%')
        // ContentAreaTouchListener takes this one positionally.
        ->bind('int $contentVersion', '%content_blocks.content_version%')
        // Upload limits: consumed by UploadController.
        ->bind('int $uploadMaxSize', '%content_blocks.upload.max_size%')
        ->bind('array $uploadAllowedMimeTypes', '%content_blocks.upload.allowed_mime_types%');

    $services->set(BlockTypeRegistry::class)
        ->public();

    // Deny all: the host must override this. See docs/guide/host-services.md
    $services->set(DenyAllAccessChecker::class);
    $services->alias(AccessCheckerInterface::class, DenyAllAccessChecker::class);

    // Throws: the host must override this too.
    $services->set(NullContentAreaUrlResolver::class);
    $services->alias(ContentAreaUrlResolverInterface::class, NullContentAreaUrlResolver::class);

    // ---------- File storage / uploads ----------

    // Throws on upload. A host opts in through `upload.directory`, or by
    // aliasing the interface to its own storage.
    $services->set(\ContentBlocks\Storage\NullFileStorage::class);
    $services->alias(\ContentBlocks\Storage\FileStorageInterface::class, \ContentBlocks\Storage\NullFileStorage::class);

    // Bridges export/import to FileStorageInterface, so with the null
    // storage behind it an export simply sees no assets.
    $services->set(NullAssetResolver::class);
    $services->set(\ContentBlocks\Asset\FileStorageAssetResolver::class);
    $services->alias(AssetResolverInterface::class, \ContentBlocks\Asset\FileStorageAssetResolver::class);

    // The single definition of "this references a stored file".
    // See docs/internals/assets.md#one-definition-of-a-reference
    $services->set(\ContentBlocks\Asset\AssetReferenceCollector::class);

    // The package's own sources go through the same autoconfigured
    // interface a host uses — no privileged internal path.
    $services->set(\ContentBlocks\Asset\ContentAreaAssetReferenceProvider::class);
    $services->set(\ContentBlocks\Asset\SectionTemplateAssetReferenceProvider::class);

    // Sweep phase. Never runs on its own: the only caller is the console
    // command, and even that reports unless given --force.
    $services->set(\ContentBlocks\Asset\AssetGarbageCollector::class)
        ->arg('$referenceProviders', tagged_iterator('content_blocks.asset_reference_provider'));

    $services->set(\ContentBlocks\Command\CollectAssetsCommand::class);

    // Denied by default, and the route 404s until a host aliases it.
    // See docs/internals/assets.md#asset-routes-are-public-on-purpose
    $services->set(\ContentBlocks\Asset\DenyAllAssetReportViewer::class);
    $services->alias(
        \ContentBlocks\Asset\AssetReportViewerInterface::class,
        \ContentBlocks\Asset\DenyAllAssetReportViewer::class,
    );

    // Passthrough by default — byte-for-byte the markup that predates it.
    // See docs/internals/assets.md#the-image-seam-ships-a-passthrough
    $services->set(\ContentBlocks\Image\PassthroughImageUrlResolver::class);
    $services->alias(\ContentBlocks\Image\ImageUrlResolverInterface::class, \ContentBlocks\Image\PassthroughImageUrlResolver::class);

    $services->load('ContentBlocks\\Twig\\Component\\', '../src/Twig/Component/')
        ->tag('twig.component');

    $services->set(\ContentBlocks\Twig\ContentBlocksExtension::class)
        ->tag('twig.extension');

    $services->set(\ContentBlocks\Twig\ImageExtension::class)
        ->tag('twig.extension');

    $services->set(\ContentBlocks\Rendering\BlockRenderer::class);
    // Rendering override seam: host decorates/replaces via the interface.
    $services->alias(\ContentBlocks\Rendering\BlockRendererInterface::class, \ContentBlocks\Rendering\BlockRenderer::class);

    // Each registered as its class and aliased to its interface, so a host
    // can decorate any of them without touching the package.
    $services->set(\ContentBlocks\Publishing\ContentAreaPublisher::class);
    $services->alias(ContentAreaPublisherInterface::class, \ContentBlocks\Publishing\ContentAreaPublisher::class);

    // ---------- Action history (Ctrl/Cmd-Z) ----------

    // See docs/internals/history.md
    $services->set(BuilderSession::class);
    $services->set(StateApplier::class);
    $services->set(DoctrineActionLogStore::class);
    $services->alias(ActionLogStoreInterface::class, DoctrineActionLogStore::class);
    $services->set(ActionJournal::class);
    $services->set(SidebarOutcome::class);

    // Decorates the concrete publisher, so a host decoration of the interface
    // (what content-blocks-i18n does) still wraps this one.
    $services->set(JournalPruningPublisher::class)
        ->decorate(\ContentBlocks\Publishing\ContentAreaPublisher::class);

    // Empty by default; cloning is unchanged without an observer. See
    // docs/internals/rendering.md#why-the-clone-notification-is-an-observer
    $services->set(\ContentBlocks\Section\BlockCloneObserverCollection::class)
        ->args([tagged_iterator('content_blocks.block_clone_observer')])
        ->public();

    $services->set(SectionCloner::class);
    $services->alias(SectionClonerInterface::class, SectionCloner::class);

    // Empty by default; an export carries the same payload it always did
    // until a bundle stores rows beside a block. See transfer.md.
    $services->set(ContentAreaExporter::class)
        ->arg('$extensions', tagged_iterator('content_blocks.transfer_extension'));
    $services->alias(ContentAreaExporterInterface::class, ContentAreaExporter::class);
    $services->set(ContentAreaImporter::class)
        ->arg('$extensions', tagged_iterator('content_blocks.transfer_extension'));
    $services->alias(ContentAreaImporterInterface::class, ContentAreaImporter::class);

    // Saving and inserting gate on the area; managing the library has none
    // to key off. See docs/internals/section-templates.md#managing-the-library
    $services->set(SectionTemplateSerializer::class);
    $services->alias(SectionTemplateSerializerInterface::class, SectionTemplateSerializer::class);
    $services->set(SectionTemplateInstantiator::class);
    $services->alias(SectionTemplateInstantiatorInterface::class, SectionTemplateInstantiator::class);
    $services->set(DenyAllSectionTemplateManager::class);
    $services->alias(SectionTemplateManagerInterface::class, DenyAllSectionTemplateManager::class);
    // Nothing to configure: a block type opts into a richer tile by
    // implementing BlockPreviewHintInterface.
    $services->set(SectionPosterBuilder::class);
    // Same seam, one level up: the tree's block rows read the same hint.
    $services->set(\ContentBlocks\Builder\AreaTreeBuilder::class);

    // Nothing here stores a clipboard: it lives in localStorage, which is
    // what makes the payload untrusted. See docs/internals/clipboard.md
    $services->set(BlockSnapshotSerializer::class);
    $services->alias(BlockSnapshotSerializerInterface::class, BlockSnapshotSerializer::class);
    $services->set(BlockDataReplayer::class);
    $services->set(ClipboardPaster::class);

    // The host's own schema generations, which the package cannot reason
    // about. See docs/internals/versioning.md#the-content-version
    $services->set(DenyOnMismatchUpgrader::class);
    $services->alias(ContentVersionUpgraderInterface::class, DenyOnMismatchUpgrader::class);

    // The package's own side of versioning. Ships empty, and must exist
    // before the first bump. See docs/internals/versioning.md
    $services->set(EnvelopeUpgradeChain::class)
        ->args([tagged_iterator('content_blocks.envelope_upgrader')]);

    // Usable out of the box; a host aliases its own for real labels.
    $services->set(DefaultContentAreaProvider::class);
    $services->alias(ContentAreaProviderInterface::class, DefaultContentAreaProvider::class);

    // Tagged rather than attributed, so the package needs no DoctrineBundle
    // dependency. See docs/internals/publishing.md
    $services->set(ContentAreaTouchListener::class)
        ->args(['%content_blocks.content_version%'])
        ->tag('doctrine.event_listener', ['event' => 'onFlush']);

    // ---------- Section settings extension hooks ----------

    // Note: the section provider and decorator interfaces are auto-tagged in
    // the bundle's build(), so a host needs no explicit tag.

    $services->set(SectionStyleRegistry::class)
        ->args([tagged_iterator('content_blocks.section_style_provider')])
        ->public();

    // Before any host provider, so a PHP provider re-using a name wins.
    $services->set(\ContentBlocks\Section\ConfigSectionStyleProvider::class)
        ->args(['%content_blocks.section_styles%']);

    // ---------- Color palette ----------

    // A host adds more through ColorPaletteProviderInterface, autoconfigured.
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

    // Pre-populates the styling sub-form; `backgroundColor` is `''`. See
    // docs/internals/forms.md#the-transparent-background-default
    $services->set(\ContentBlocks\Section\CoreStylingDefaults::class);

    // The root-level mirror of CoreStylingDefaults, so a centered section
    // with no explicit value still picks a cap.
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

    // The block-side mirror; `backgroundColor` is `''` here too. See
    // docs/internals/forms.md#the-transparent-background-default
    $services->set(\ContentBlocks\Block\CoreBlockStylingDefaults::class);

    $services->set(\ContentBlocks\Block\BlockDataDefaults::class)
        ->args([tagged_iterator('content_blocks.block_data_defaults')])
        ->public();

    // ---------- Block data resolution (render payload) ----------

    // Tagged by hand for its priority, hence autoconfigure(false):
    // autoconfiguration would tag it twice and it would run twice.
    $services->set(\ContentBlocks\Rendering\CoreBlockDataResolver::class)
        ->autoconfigure(false)
        ->tag('content_blocks.block_data_resolver', ['priority' => 256]);

    $services->set(\ContentBlocks\Rendering\BlockDataResolverCollection::class)
        ->args([tagged_iterator('content_blocks.block_data_resolver')])
        ->public();

    // Shared by both restore paths, so the union rule lives in one place.
    // See docs/internals/clipboard.md#which-keys-a-block-type-can-hold
    $services->set(\ContentBlocks\Block\BlockDataKeys::class);

    // Minted on the draft-write path, so a reorder never shifts what
    // per-entry information points at. See docs/internals/clipboard.md
    $services->set(\ContentBlocks\Block\CollectionItemIds::class);

    // One-off normalization of content stored before `_id` existed. The
    // #[AsCommand] attribute is picked up by console.command autoconfiguration.
    $services->set(\ContentBlocks\Command\BackfillCollectionIdsCommand::class);

    // ---------- Builder topbar actions ----------

    // Merges provider contributions and a form's own `topbar_actions`.
    // See docs/internals/builder-extensions.md#ordering-and-collisions
    $services->set(\ContentBlocks\Builder\BuilderActionCollection::class)
        ->args([tagged_iterator('content_blocks.builder_action_provider')])
        ->public();

    // Read by the shell template itself, so they appear wherever it renders.
    // See docs/internals/builder-extensions.md#two-halves-of-one-seam
    $services->set(\ContentBlocks\Builder\BuilderShellFragmentCollection::class)
        ->args([tagged_iterator('content_blocks.builder_shell_extension')])
        ->public();

    $services->set(\ContentBlocks\Twig\ShellFragmentsExtension::class)
        ->tag('twig.extension');

    $services->set(\ContentBlocks\Twig\HistoryStateExtension::class)
        ->tag('twig.extension');

    // ---------- Content translation (convention only) ----------

    // Neither has a consumer here: the core ships the convention so it
    // freezes with 1.0. See docs/internals/forms.md
    $services->set(\ContentBlocks\Form\Extension\TranslatableFieldTypeExtension::class)
        ->tag('form.type_extension');

    $services->set(\ContentBlocks\Translation\TranslatableFields::class);
    $services->alias(
        \ContentBlocks\Translation\TranslatableFieldsInterface::class,
        \ContentBlocks\Translation\TranslatableFields::class,
    );

    // The attribute is a marker read by reflection, never instantiated,
    // hence the exclude. Its pairs come from BlockFormExtensionPass.
    $services->load('ContentBlocks\\Form\\', '../src/Form/')
        ->exclude('../src/Form/Extension/AsBlockFormExtension.php');

    $services->load('ContentBlocks\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');
};
