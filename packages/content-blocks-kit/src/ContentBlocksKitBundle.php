<?php

declare(strict_types=1);

namespace ContentBlocks\Kit;

use ContentBlocks\Kit\Block\AbstractKitBlock;
use ContentBlocks\Kit\Block\AccordionBlock;
use ContentBlocks\Kit\Block\AlertBlock;
use ContentBlocks\Kit\Block\BreadcrumbBlock;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Block\CardBlock;
use ContentBlocks\Kit\Block\DividerBlock;
use ContentBlocks\Kit\Block\EmbedBlock;
use ContentBlocks\Kit\Block\GalleryBlock;
use ContentBlocks\Kit\Block\HtmlRawBlock;
use ContentBlocks\Kit\Block\IconBlock;
use ContentBlocks\Kit\Block\ImageBlock;
use ContentBlocks\Kit\Block\ListBlock;
use ContentBlocks\Kit\Block\TableBlock;
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Block\TabsBlock;
use ContentBlocks\Kit\Block\TextBlock;
use ContentBlocks\Kit\Block\TitleBlock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ContentBlocksKitBundle extends AbstractBundle
{
    /**
     * The blocks this kit ships, keyed by their `getType()`. Single source
     * of truth for both the config tree and conditional registration.
     *
     * @var array<string, class-string<AbstractKitBlock>>
     */
    public const BLOCKS = [
        'title' => TitleBlock::class,
        'text' => TextBlock::class,
        'rich_text' => RichTextBlock::class,
        'image' => ImageBlock::class,
        'gallery' => GalleryBlock::class,
        'button' => ButtonBlock::class,
        'card' => CardBlock::class,
        'list' => ListBlock::class,
        'icon' => IconBlock::class,
        'alert' => AlertBlock::class,
        'divider' => DividerBlock::class,
        'accordion' => AccordionBlock::class,
        'table' => TableBlock::class,
        'embed' => EmbedBlock::class,
        'breadcrumb' => BreadcrumbBlock::class,
        'html_raw' => HtmlRawBlock::class,
        'tabs' => TabsBlock::class,
    ];

    /**
     * Semantic config: enable/disable each block and pass per-block options.
     *
     *     content_blocks_kit:
     *         blocks:
     *             html_raw: { enabled: false }   # drop a block entirely
     *             gallery:
     *                 enabled: true
     *                 options: { max_columns: 6 }
     *
     * Blocks omitted from config default to enabled with their coded option
     * defaults. Disabling a block un-registers its service, so it never
     * reaches the BlockTypeRegistry / picker.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line method.notFound (ArrayNodeDefinition is the concrete root)
        $definition->rootNode()
            ->children()
                ->arrayNode('blocks')
                    ->info('Per-block enable flag and options, keyed by block type.')
                    ->useAttributeAsKey('type')
                    ->arrayPrototype()
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->variableNode('options')
                                ->info('Block-specific options; merged over the block\'s coded defaults.')
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Shared services (form types); block services are registered
        // conditionally below, not bulk-loaded.
        $container->import('../config/services.php');

        $services = $container->services();

        foreach (self::resolveBlocks($config) as $class => $options) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->arg('$options', $options);
        }
    }

    /**
     * Resolve which block services to register and with what options, from
     * the processed `content_blocks_kit` config. Pure (no container) so the
     * gating + option-merge logic is unit-testable.
     *
     * @param array{blocks?: array<string, array{enabled?: bool, options?: array<string, mixed>}>} $config
     * @return array<class-string<AbstractKitBlock>, array<string, mixed>>  Enabled block class => merged options.
     */
    public static function resolveBlocks(array $config): array
    {
        $blocksConfig = $config['blocks'] ?? [];
        $out = [];

        foreach (self::BLOCKS as $type => $class) {
            $blockConfig = $blocksConfig[$type] ?? [];
            if (($blockConfig['enabled'] ?? true) === false) {
                continue;
            }

            // Merge coded defaults with host overrides so the block always
            // receives a fully-populated option set.
            $out[$class] = array_replace($class::defaultOptions(), $blockConfig['options'] ?? []);
        }

        return $out;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register assets path so AssetMapper + StimulusBundle can discover controllers.
        // The @ContentBlocksKit Twig namespace is auto-detected by AbstractBundle from <BundleRoot>/templates/,
        // which also gives `templates/bundles/ContentBlocksKitBundle/` priority for host overrides.
        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $this->getPath() . '/assets' => '@klehm/content-blocks-kit',
                ],
            ],
        ]);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
