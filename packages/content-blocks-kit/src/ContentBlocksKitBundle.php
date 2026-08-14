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
     * Blocks shipped but OFF by default — the host must opt in explicitly.
     * `html_raw` renders unescaped markup (`{{ html|raw }}`), so it trusts its
     * editors; keeping it out of the picker until a host consciously enables it
     * (`content_blocks_kit.blocks.html_raw.enabled: true`) is the safe default.
     *
     * @var list<string>
     */
    public const DEFAULT_DISABLED = ['html_raw'];

    /**
     * Semantic config: enable/disable each block and pass per-block options.
     *
     *     content_blocks_kit:
     *         blocks:
     *             tabs: { enabled: false }               # drop a block entirely
     *             html_raw: { enabled: true }            # opt into a DEFAULT_DISABLED block
     *             gallery:
     *                 options: { max_columns: 6 }        # block-level knobs
     *             button:
     *                 choices:  { variant: [primary, secondary] }  # restrict/reorder a ChoiceType
     *                 defaults: { align: center }                  # override initial field values
     *             alert:
     *                 # A value:label map replaces the set instead of filtering
     *                 # it, so it can add values the kit never coded. Labels go
     *                 # through the field's translation domain.
     *                 choices:  { type: { info: 'cb_kit.block.alert.type.info', tip: 'Astuce' } }
     *
     * Blocks omitted from config default to enabled with their coded option
     * defaults — except {@see self::DEFAULT_DISABLED} blocks, which stay off
     * until explicitly enabled. Disabling a block un-registers its service, so
     * it never reaches the BlockTypeRegistry / picker.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line method.notFound (ArrayNodeDefinition is the concrete root)
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
     * Auto-tag host-registered rich-text editors, so wiring a third editor
     * (Quill, Trix, an in-house one) is a service declaration and nothing
     * else — the same deal the core gives palette and section-style
     * providers.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(RichTextEditorInterface::class)
            ->addTag('content_blocks_kit.rich_text_editor');

        // Same deal for icons: contributing a glyph is a service declaration.
        // It is also the only way to *add* to the icon block's picker — see
        // IconProviderInterface on why `choices` cannot do that job alone.
        $container->registerForAutoconfiguration(IconProviderInterface::class)
            ->addTag('content_blocks_kit.icon_provider');
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Shared services (form types); block services are registered
        // conditionally below, not bulk-loaded.
        $container->import('../config/services.php');

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
     * Resolve which block services to register and with what options, from
     * the processed `content_blocks_kit` config. Pure (no container) so the
     * gating + option-merge logic is unit-testable.
     *
     * @param array{blocks?: array<string, array{enabled?: bool, options?: array<string, mixed>, choices?: array<string, list<string>>, defaults?: array<string, mixed>}>} $config
     * @return array<class-string<AbstractKitBlock>, array{options: array<string, mixed>, choices: array<string, list<string>>, defaults: array<string, mixed>}>
     *         Enabled block class => resolved options + raw choice/default overrides.
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
                // Choice/default overrides are consumed inside the block (against
                // its coded schema), so they pass through raw here.
                'choices' => $blockConfig['choices'] ?? [],
                'defaults' => $blockConfig['defaults'] ?? [],
            ];
        }

        return $out;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register the assets path so AssetMapper + StimulusBundle can discover the
        // controllers. Guarded for Webpack Encore hosts — same reason as the core
        // bundle: prepending `paths` would enable a component that is not installed.
        // The @ContentBlocksKit Twig namespace is auto-detected by AbstractBundle from <BundleRoot>/templates/,
        // which also gives `templates/bundles/ContentBlocksKitBundle/` priority for host overrides.
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
