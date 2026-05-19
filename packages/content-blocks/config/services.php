<?php

declare(strict_types=1);

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Doctrine\ContentAreaTouchListener;
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
use ContentBlocks\Service\SectionCloner;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    // Section-level defaults. The single parameter is shared by
    // BuiltInSectionDecorator, CoreSectionDefaults and SectionSettingsType
    // so a host can override it in one place and have the form pre-fill,
    // the placeholder, and the rendered fallback all move together.
    $container->parameters()
        ->set('content_blocks.section.default_max_width', 1320);

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        // Targeted binding: only services whose constructor literally
        // declares `int $defaultMaxWidth` pick this up — currently
        // BuiltInSectionDecorator, CoreSectionDefaults, and
        // SectionSettingsType.
        ->bind('int $defaultMaxWidth', '%content_blocks.section.default_max_width%');

    $services->set(BlockTypeRegistry::class)
        ->public();

    // Default: deny all access. Host app must override with its own implementation.
    $services->set(DenyAllAccessChecker::class);
    $services->alias(AccessCheckerInterface::class, DenyAllAccessChecker::class);

    // Default: throws on resolve. Host app must override with its own implementation.
    $services->set(NullContentAreaUrlResolver::class);
    $services->alias(ContentAreaUrlResolverInterface::class, NullContentAreaUrlResolver::class);

    $services->load('ContentBlocks\\Twig\\Component\\', '../src/Twig/Component/')
        ->tag('twig.component');

    $services->set(\ContentBlocks\Twig\ContentBlocksExtension::class)
        ->tag('twig.extension');

    $services->set(\ContentBlocks\Rendering\BlockRenderer::class);

    $services->set(\ContentBlocks\Service\ContentAreaPublisher::class);

    $services->set(SectionCloner::class);

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
