<?php

declare(strict_types=1);

namespace ContentBlocks;

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
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
