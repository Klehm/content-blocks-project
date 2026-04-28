<?php

declare(strict_types=1);

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Preview\NullContentAreaUrlResolver;
use ContentBlocks\Section\BuiltInSectionDecorator;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\DenyAllAccessChecker;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

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

    $services->set(SectionDecoratorCollection::class)
        ->args([tagged_iterator('content_blocks.section_decorator')])
        ->public();

    $services->set(SectionSettingsDefaults::class)
        ->args([tagged_iterator('content_blocks.section_settings_defaults')])
        ->public();

    $services->load('ContentBlocks\\Form\\', '../src/Form/');

    $services->load('ContentBlocks\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');
};
