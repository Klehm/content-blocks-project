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
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Block\TableBlock;
use ContentBlocks\Kit\Block\TabsBlock;
use ContentBlocks\Kit\Block\TextBlock;
use ContentBlocks\Kit\Block\TitleBlock;
use ContentBlocks\Kit\DependencyInjection\KitBlockConfigPass;
use ContentBlocks\Kit\Icon\IconProviderInterface;
use ContentBlocks\Kit\RichText\RichTextEditorInterface;
use Symfony\Component\AssetMapper\AssetMapper;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ContentBlocksKitBundle extends AbstractBundle
{
    /**
     * Raw `blocks` config, kept for {@see KitBlockConfigPass}, registered in
     * `build()` long before there is configuration to read.
     *
     * @var array<string, array{
     *     enabled?: bool,
     *     options?: array<string, mixed>,
     *     choices?: array<string, mixed>,
     *     defaults?: array<string, mixed>,
     * }>
     */
    private array $blocksConfig = [];

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
     * Shipped but OFF until a host opts in explicitly.
     *
     * @see docs/internals/kit.md#disabling-and-why-html_raw-is-off
     *
     * @var list<string>
     */
    public const DEFAULT_DISABLED = ['html_raw'];

    /**
     * Semantic config: enable or disable each block, and pass it `options`,
     * `choices` and `defaults`.
     *
     * @see docs/internals/kit.md#three-levers-one-source-of-truth
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('blocks')
                    ->info('Per-block enable flag, options, choice restrictions and default overrides, keyed by block type.')
                    ->useAttributeAsKey('type')
                    ->arrayPrototype()
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->variableNode('options')
                                ->info('Block-specific knobs (e.g. max_columns); merged over the block\'s coded defaults.')
                                ->defaultValue([])
                            ->end()
                            ->variableNode('choices')
                                ->info('Per-field choice override, keyed by field name. A list restricts/reorders the coded set and ignores unknown values ({ variant: [primary, secondary] }); a value:label map replaces it outright and may add values ({ variant: { ghost: "Ghost" } }).')
                                ->defaultValue([])
                            ->end()
                            ->variableNode('defaults')
                                ->info('Per-field overrides of the block\'s initial data, keyed by field name (e.g. { align: center }).')
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Auto-tags host-registered rich-text editors and icon providers, so
     * wiring either is a service declaration and nothing else.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(RichTextEditorInterface::class)
            ->addTag('content_blocks_kit.rich_text_editor');

        // The only way to *add* to the icon picker: `choices` filters a set
        // rather than extending one.
        $container->registerForAutoconfiguration(IconProviderInterface::class)
            ->addTag('content_blocks_kit.icon_provider');

        // Reaches the blocks a host subclassed, which loadExtension() cannot.
        // See kit.md#disabling-and-why-html_raw-is-off
        $container->addCompilerPass(new KitBlockConfigPass(fn (): array => $this->blocksConfig));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Shared services (form types); block services are registered
        // conditionally below, not bulk-loaded.
        $container->import('../config/services.php');

        $this->blocksConfig = $config['blocks'] ?? [];

        $services = $container->services();

        foreach (self::resolveBlocks($config) as $class => $blockConfig) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->arg('$options', $blockConfig['options'])
                ->arg('$choiceOverrides', $blockConfig['choices'])
                ->arg('$defaultOverrides', $blockConfig['defaults']);
        }
    }

    /**
     * Which block services to register, and with what. Pure — no container —
     * so the gating and merge logic is unit-testable.
     *
     * @param array{blocks?: array<string, array{
     *     enabled?: bool,
     *     options?: array<string, mixed>,
     *     choices?: array<string, list<string>>,
     *     defaults?: array<string, mixed>,
     * }>} $config
     *
     * @return array<class-string<AbstractKitBlock>, array{
     *     options: array<string, mixed>,
     *     choices: array<string, list<string>>,
     *     defaults: array<string, mixed>,
     * }>
     */
    public static function resolveBlocks(array $config): array
    {
        $blocksConfig = $config['blocks'] ?? [];
        $out = [];

        foreach (self::BLOCKS as $type => $class) {
            $blockConfig = $blocksConfig[$type] ?? [];
            $defaultEnabled = !\in_array($type, self::DEFAULT_DISABLED, true);
            if (($blockConfig['enabled'] ?? $defaultEnabled) === false) {
                continue;
            }

            $out[$class] = [
                // Merge coded defaults with host overrides so the block always
                // receives a fully-populated option set.
                'options' => array_replace($class::defaultOptions(), $blockConfig['options'] ?? []),
                // Consumed inside the block against its coded schema, so
                // they pass through raw here.
                'choices' => $blockConfig['choices'] ?? [],
                'defaults' => $blockConfig['defaults'] ?? [],
            ];
        }

        return $out;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Guarded for the same reason as the core bundle. See
        // docs/internals/bundle-boot.md#the-assetmapper-prepend
        if (class_exists(AssetMapper::class)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $this->getPath() . '/assets' => '@klehm/content-blocks-kit',
                    ],
                ],
            ]);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
