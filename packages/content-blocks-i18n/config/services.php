<?php

declare(strict_types=1);

use ContentBlocks\I18n\Command\TranslateAreaCommand;
use ContentBlocks\I18n\Command\TranslationStatusCommand;
use ContentBlocks\I18n\Controller\MachineTranslationController;
use ContentBlocks\I18n\Controller\WorkbenchController;
use ContentBlocks\I18n\Field\FieldMetadataReader;
use ContentBlocks\I18n\Field\TranslatableFieldCatalog;
use ContentBlocks\I18n\Lifecycle\TranslationCloneObserver;
use ContentBlocks\I18n\Lifecycle\TranslationPublisher;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\RequestRenderLocaleResolver;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Machine\NullTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Rendering\PrefetchingBlockRenderer;
use ContentBlocks\I18n\Rendering\TranslationBlockDataResolver;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    // Defaults, normally overwritten by the bundle's semantic config
    // (`content_blocks_i18n.*`) in loadExtension(). An installation that
    // registers the bundle and configures nothing has no target locales, so
    // every seam here is a no-op and rendered output is unchanged.
    $container->parameters()
        ->set('content_blocks_i18n.source_locale', 'en')
        ->set('content_blocks_i18n.locales', [])
        ->set('content_blocks_i18n.locale_labels', [])
        ->set('content_blocks_i18n.machine.default', null);

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // ---------- Locales ----------

    $services->set(TranslationLocales::class)
        ->args([
            param('content_blocks_i18n.source_locale'),
            param('content_blocks_i18n.locales'),
            param('content_blocks_i18n.locale_labels'),
        ])
        ->public();

    $services->set(RequestRenderLocaleResolver::class);
    $services->alias(RenderLocaleResolverInterface::class, RequestRenderLocaleResolver::class);

    // ---------- Storage ----------

    $services->set(BlockTranslationRepository::class)->tag('doctrine.repository_service');
    $services->set(TranslationStore::class)->public();
    $services->set(TranslationWriter::class)->public();

    // ---------- Field catalog ----------

    $services->set(FieldMetadataReader::class);
    $services->set(TranslatableFieldCatalog::class)->public();
    $services->set(TranslationInspector::class)->public();

    // ---------- Render path ----------

    // Tagged by hand with a priority so it lands between the core's seeding
    // resolver (256) and whatever a host registers at the default 0 — hence
    // autoconfigure(false), which would otherwise add the tag a second time
    // and merge the locale payload twice.
    $services->set(TranslationBlockDataResolver::class)
        ->autoconfigure(false)
        ->autowire()
        ->tag('content_blocks.block_data_resolver', ['priority' => TranslationBlockDataResolver::PRIORITY]);

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

    // ---------- Machine translation ----------

    $services->set(NullTranslationProvider::class);

    $services->set(TranslationProviderRegistry::class)
        ->args([
            tagged_iterator('content_blocks_i18n.translation_provider'),
            param('content_blocks_i18n.machine.default'),
        ])
        ->public();

    $services->set(MachineTranslator::class)->public();

    // ---------- HTTP + CLI ----------

    $services->set(WorkbenchController::class)->tag('controller.service_arguments');
    $services->set(MachineTranslationController::class)->tag('controller.service_arguments');

    $services->set(TranslateAreaCommand::class);
    $services->set(TranslationStatusCommand::class);
};
