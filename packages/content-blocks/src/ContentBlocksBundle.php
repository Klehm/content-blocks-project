<?php

declare(strict_types=1);

namespace ContentBlocks;

use ContentBlocks\Block\BlockDataDefaultsProviderInterface;
use ContentBlocks\Block\BlockDecoratorInterface;
use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\DependencyInjection\BlockTypeCompilerPass;
use ContentBlocks\Section\SectionDecoratorInterface;
use ContentBlocks\Section\SectionSettingsDefaultsProviderInterface;
use ContentBlocks\Section\SectionStyleProviderInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ContentBlocksBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register assets path so AssetMapper + StimulusBundle can discover controllers
        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $this->getPath() . '/assets' => '@klehm/content-blocks',
                ],
            ],
        ]);

        // Auto-register the form theme so `form_row(form.contentArea)` renders the builder out of the box.
        // The @ContentBlocks namespace itself is auto-detected by AbstractBundle from <BundleRoot>/templates/,
        // which also gives `templates/bundles/ContentBlocksBundle/` priority for host overrides.
        $builder->prependExtensionConfig('twig', [
            'form_themes' => [
                '@ContentBlocks/form/content_area_widget.html.twig',
            ],
        ]);

        // Map Twig Components shipped by this bundle so cache:clear doesn't fail
        // on a missing namespace right after composer require. ux-twig-component
        // is a hard dependency of this package, so the extension is always loaded.
        $builder->prependExtensionConfig('twig_component', [
            'defaults' => [
                'ContentBlocks\\Twig\\Component\\' => '@ContentBlocks/components/',
            ],
        ]);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new BlockTypeCompilerPass());

        $container->registerAttributeForAutoconfiguration(
            AsContentBlock::class,
            static function (ChildDefinition $definition, AsContentBlock $attribute, \Reflector $reflector): void {
                $definition->addTag('content_blocks.block_type');
            },
        );

        // Globally auto-tag host implementations of the section extension
        // points so they don't need any wiring beyond `autoconfigure: true`
        // on the host's services.yaml.
        $container->registerForAutoconfiguration(SectionStyleProviderInterface::class)
            ->addTag('content_blocks.section_style_provider');
        $container->registerForAutoconfiguration(SectionDecoratorInterface::class)
            ->addTag('content_blocks.section_decorator');
        $container->registerForAutoconfiguration(SectionSettingsDefaultsProviderInterface::class)
            ->addTag('content_blocks.section_settings_defaults');
        $container->registerForAutoconfiguration(BlockDecoratorInterface::class)
            ->addTag('content_blocks.block_decorator');
        $container->registerForAutoconfiguration(BlockDataDefaultsProviderInterface::class)
            ->addTag('content_blocks.block_data_defaults');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
