<?php

declare(strict_types=1);

use ContentBlocks\I18n\Asset\TranslationAssetReferenceProvider;
use ContentBlocks\I18n\Command\TranslateAreaCommand;
use ContentBlocks\I18n\Command\TranslationStatusCommand;
use ContentBlocks\I18n\Controller\AssetController;
use ContentBlocks\I18n\Controller\MachineTranslationController;
use ContentBlocks\I18n\Controller\WorkbenchController;
use ContentBlocks\I18n\Controller\WorkbenchPageController;
use ContentBlocks\I18n\Field\FieldMetadataReader;
use ContentBlocks\I18n\Field\TranslatableFieldCatalog;
use ContentBlocks\I18n\Lifecycle\TranslationCloneObserver;
use ContentBlocks\I18n\Lifecycle\TranslationPublisher;
use ContentBlocks\I18n\Locale\ConfiguredLocaleFallbacksProvider;
use ContentBlocks\I18n\Locale\ConfiguredTargetLocalesProvider;
use ContentBlocks\I18n\Locale\LocaleFallbacksProviderInterface;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use ContentBlocks\I18n\Locale\NullLocalizedPageUrlResolver;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\RequestRenderLocaleResolver;
use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Locale\TranslationLocalesFactory;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Machine\NullTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Preview\PreviewLocaleListener;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Rendering\PrefetchingBlockRenderer;
use ContentBlocks\I18n\Rendering\TranslationBlockDataResolver;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Transfer\TranslationTransferExtension;
use ContentBlocks\I18n\Twig\I18nExtension;
use ContentBlocks\I18n\Workbench\PageBackUrlResolver;
use ContentBlocks\I18n\Workbench\WorkbenchBackUrlResolverInterface;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Rendering\BlockRendererInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    // Configuring nothing leaves no target locales, so every seam is a
    // no-op and the rendered output is unchanged.
    $container->parameters()
        ->set('content_blocks_i18n.source_locale', 'en')
        ->set('content_blocks_i18n.locales', [])
        ->set('content_blocks_i18n.locale_labels', [])
        ->set('content_blocks_i18n.fallbacks', [])
        ->set('content_blocks_i18n.machine.default', null)
        ->set('content_blocks_i18n.workbench.public_links', true);

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // ---------- Locales ----------

    $services->set(ConfiguredTargetLocalesProvider::class)
        ->args([param('content_blocks_i18n.locales')]);
    $services->alias(TargetLocalesProviderInterface::class, ConfiguredTargetLocalesProvider::class);

    $services->set(ConfiguredLocaleFallbacksProvider::class)
        ->args([param('content_blocks_i18n.fallbacks')]);
    $services->alias(LocaleFallbacksProviderInterface::class, ConfiguredLocaleFallbacksProvider::class);

    $services->set(TranslationLocales::class)
        ->factory([TranslationLocalesFactory::class, 'create'])
        ->args([
            param('content_blocks_i18n.source_locale'),
            service(TargetLocalesProviderInterface::class),
            param('content_blocks_i18n.locale_labels'),
            service(LocaleFallbacksProviderInterface::class),
        ])
        ->public();

    $services->set(RequestRenderLocaleResolver::class);
    $services->alias(RenderLocaleResolverInterface::class, RequestRenderLocaleResolver::class);

    // ---------- Storage ----------

    $services->set(BlockTranslationRepository::class)->tag('doctrine.repository_service');
    $services->set(ContentBlocks\I18n\Repository\ColumnTranslationRepository::class)->tag('doctrine.repository_service');
    $services->set(TranslationStore::class)->public();
    $services->set(TranslationWriter::class)->public();

    // ---------- Field catalog ----------

    $services->set(FieldMetadataReader::class);
    $services->set(TranslatableFieldCatalog::class)->public();
    $services->set(TranslationInspector::class)->public();

    // ---------- Render path ----------

    // Tagged by hand for its priority, hence autoconfigure(false): it would
    // otherwise tag twice and merge the locale payload twice.
    $services->set(TranslationBlockDataResolver::class)
        ->autoconfigure(false)
        ->autowire()
        ->tag('content_blocks.block_data_resolver', ['priority' => TranslationBlockDataResolver::PRIORITY]);

    // A tab title in the render locale; autoconfigured through its interface.
    $services->set(ContentBlocks\I18n\Rendering\TranslationColumnSettingsResolver::class);

    // Warms the store with one query per area so the resolver above never
    // issues a query of its own. Purely an optimization — see the class.
    $services->set(PrefetchingBlockRenderer::class)
        ->decorate(BlockRendererInterface::class)
        ->args([service('.inner')]);

    // ---------- Lifecycle ----------

    $services->set(TranslationPublisher::class)
        ->decorate(ContentAreaPublisherInterface::class)
        ->args([service('.inner')]);

    $services->set(TranslationCloneObserver::class);

    // Tells the builder a translation is waiting, and which locales.
    $services->set(ContentBlocks\I18n\Lifecycle\TranslationDrafts::class)->public();

    // Both implement a core seam newer than this package's floor, so they are
    // registered only when the core has it.
    if (interface_exists(ContentBlocks\Publishing\UnpublishedChangesProviderInterface::class)) {
        $services->set(ContentBlocks\I18n\Lifecycle\TranslationUnpublishedChanges::class);
    }
    if (interface_exists(ContentBlocks\Snapshot\SnapshotExtensionInterface::class)) {
        $services->set(ContentBlocks\I18n\Snapshot\TranslationSnapshotExtension::class);
    }

    // The same duty for a payload that leaves the installation. See
    // docs/internals/i18n.md#translations-in-an-export
    $services->set(TranslationTransferExtension::class);

    // ---------- Machine translation ----------

    $services->set(NullTranslationProvider::class);

    $services->set(TranslationProviderRegistry::class)
        ->args([
            tagged_iterator('content_blocks_i18n.translation_provider'),
            param('content_blocks_i18n.machine.default'),
        ])
        ->public();

    $services->set(MachineTranslator::class)->public();

    // ---------- Preview ----------

    // Lets the workbench preview the host's own page in the target language
    // with no second URL resolver. See docs/internals/i18n.md#the-preview-pane
    $services->set(PreviewLocaleListener::class);

    // ---------- Workbench ----------

    // The back arrow; a host aliases the interface to point it at its admin.
    $services->set(PageBackUrlResolver::class);
    $services->alias(WorkbenchBackUrlResolverInterface::class, PageBackUrlResolver::class);

    // No URL for any language until the host aliases the interface.
    $services->set(NullLocalizedPageUrlResolver::class);
    $services->alias(LocalizedPageUrlResolverInterface::class, NullLocalizedPageUrlResolver::class);

    $services->set(WorkbenchPageController::class)
        ->arg('$publicLinks', param('content_blocks_i18n.workbench.public_links'))
        ->tag('controller.service_arguments');

    // ---------- Assets ----------

    // Translated values reference files no block's data mentions. See
    // docs/internals/i18n.md#translated-values-hold-asset-references-too
    $services->set(TranslationAssetReferenceProvider::class);

    // ---------- Twig ----------

    $services->set(I18nExtension::class)->tag('twig.extension');

    // hreflang alternates for the host's public <head>.
    $services->set(ContentBlocks\I18n\Locale\AlternatePageLinks::class);
    $services->set(ContentBlocks\I18n\Twig\HreflangExtension::class)->tag('twig.extension');

    // ---------- HTTP + CLI ----------

    $services->set(WorkbenchController::class)->tag('controller.service_arguments');
    $services->set(MachineTranslationController::class)->tag('controller.service_arguments');
    $services->set(ContentBlocks\I18n\Controller\LocalePublishController::class)->tag('controller.service_arguments');
    $services->set(AssetController::class)->tag('controller.service_arguments');

    $services->set(TranslateAreaCommand::class);
    $services->set(TranslationStatusCommand::class);
};
