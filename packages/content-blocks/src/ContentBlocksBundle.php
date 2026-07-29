<?php

declare(strict_types=1);

namespace ContentBlocks;

use ContentBlocks\Block\BlockDataDefaultsProviderInterface;
use ContentBlocks\Block\BlockDecoratorInterface;
use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\DependencyInjection\BlockFormExtensionPass;
use ContentBlocks\DependencyInjection\BlockTypeCompilerPass;
use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Palette\ColorPaletteProviderInterface;
use ContentBlocks\Section\SectionDecoratorInterface;
use ContentBlocks\Section\SectionSettingsDefaultsProviderInterface;
use ContentBlocks\Section\SectionStyleProviderInterface;
use Symfony\Component\AssetMapper\AssetMapper;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ContentBlocksBundle extends AbstractBundle
{
    /**
     * Semantic bundle config — the declarative shortcut for what the
     * provider interfaces do in PHP:
     *
     *     content_blocks:
     *         section:
     *             default_width_mode: centered   # 'full'|'centered'
     *             default_max_width: 1320
     *         palette:                           # PaletteColorType dropdown
     *             - { label: 'Primary', color: '#eb0540' }
     *         styles:                            # section style presets
     *             - name: boxed
     *               label: 'Boxed'
     *               css_class: 'my-section--boxed'
     *               settings: { styling: { padding: { d: { top: 40, bottom: 40 } } } }
     *         upload:
     *             directory: '%kernel.project_dir%/public/uploads/content-blocks'
     *             public_prefix: '/uploads/content-blocks'
     *             max_size: 10485760             # bytes
     *             allowed_mime_types: ['image/jpeg', 'image/png']
     *
     * Interfaces stay the power-user path (ColorPaletteProviderInterface,
     * SectionStyleProviderInterface, FileStorageInterface…); config and PHP
     * providers merge together in the registries.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line method.notFound (ArrayNodeDefinition is the concrete root)
        $definition->rootNode()
            ->children()
                ->integerNode('content_version')
                    ->info('Schema generation of YOUR block data. Bump it whenever anything that shapes stored block data changes — your own blocks, a kit upgrade, or a core upgrade note saying so. Stamped onto content as it is written, so you can target what predates a change: WHERE content_version < N.')
                    ->min(1)
                    ->defaultValue(1)
                ->end()
                ->arrayNode('section')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('default_width_mode')
                            ->values(['full', 'centered'])
                            ->defaultValue('full')
                        ->end()
                        ->integerNode('default_max_width')
                            ->min(1)
                            ->defaultValue(1320)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('palette')
                    ->info('Named colors offered by the palette color picker.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('color')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('section_styles')
                    ->info('Section style presets (CSS class + optional settings values).')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('css_class')->defaultValue('')->end()
                        ->end()
                        ->append($this->presetSettingsNode())
                    ->end()
                ->end()
                ->arrayNode('upload')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')
                            ->info('Filesystem directory for LocalFileStorage; leave null to keep uploads disabled (NullFileStorage) or wire your own FileStorageInterface.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('public_prefix')->defaultValue('/uploads/content-blocks')->end()
                        ->integerNode('max_size')->min(1)->defaultValue(10485760)->end()
                        ->arrayNode('allowed_mime_types')
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                'image/jpeg',
                                'image/png',
                                'image/gif',
                                'image/webp',
                                'image/svg+xml',
                                'application/pdf',
                            ])
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Typed `section_styles[].settings` node — a subset of a section's own
     * settings that a preset applies underneath the section (explicit user
     * values win key-by-key at render time, see BlockRenderer::applyPresetSettings).
     *
     * Typed (rather than a free-form variableNode) so preset YAML is validated
     * and self-documenting; the shape mirrors what SectionSettingsType / StylingType
     * persist to `draft_settings`.
     */
    private function presetSettingsNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('settings');
        $node
            ->info('Section settings applied by the preset (subset of a section\'s settings).')
            ->children()
                ->scalarNode('classes')->end()
                ->enumNode('widthMode')->values(['full', 'centered'])->end()
                ->integerNode('maxWidth')->min(1)->end()
                ->scalarNode('columnWidths')->end()
                ->scalarNode('styleName')->end()
                ->booleanNode('stylingCustom')->end()
            ->end()
            ->append($this->stylingNode());

        return $node;
    }

    /**
     * The `styling` sub-tree (padding/margin/gap responsive boxes, background,
     * min-height, vertical alignment). Keys are spelled out
     * (`desktop`/`tablet`/`mobile`); leaves are left without defaults so a preset
     * carries only the keys it explicitly sets.
     */
    private function stylingNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('styling');
        $node
            ->append($this->responsiveBoxNode('padding'))
            ->append($this->responsiveBoxNode('margin'))
            ->append($this->responsiveGapNode())
            ->children()
                ->scalarNode('backgroundColor')->end()
                ->arrayNode('minHeight')
                    ->children()
                        ->integerNode('value')->min(0)->end()
                        ->enumNode('unit')->values(['px', 'vh'])->end()
                    ->end()
                ->end()
                ->enumNode('verticalAlign')->values(['start', 'center', 'end'])->end()
            ->end();

        return $node;
    }

    /**
     * A responsive box (`desktop`/`tablet`/`mobile` → {top,right,bottom,left: int,
     * linked: bool}). `linked` is UI state (the "link sides" toggle); the render
     * decorators ignore it but it is persisted so the editor can restore the toggle.
     */
    private function responsiveBoxNode(string $name): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $children = $node->children();
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            $children
                ->arrayNode($viewport)
                    ->children()
                        ->integerNode('top')->end()
                        ->integerNode('right')->end()
                        ->integerNode('bottom')->end()
                        ->integerNode('left')->end()
                        ->booleanNode('linked')->end()
                    ->end()
                ->end();
        }

        return $node;
    }

    /** A responsive single length (`desktop`/`tablet`/`mobile` → int px), used for the column gap. */
    private function responsiveGapNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('gap');
        $children = $node->children();
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            $children->integerNode($viewport)->min(0)->end();
        }

        return $node;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // Project the processed semantic config onto the container
        // parameters consumed by services.php. App-level parameter overrides
        // still win: MergeExtensionConfigurationPass re-applies the app's
        // parameters on top of extension-set ones.
        $container->parameters()
            ->set('content_blocks.content_version', $config['content_version'])
            ->set('content_blocks.section.default_width_mode', $config['section']['default_width_mode'])
            ->set('content_blocks.section.default_max_width', $config['section']['default_max_width'])
            ->set('content_blocks.palette', $config['palette'])
            ->set('content_blocks.section_styles', $config['section_styles'])
            ->set('content_blocks.upload.public_prefix', $config['upload']['public_prefix'])
            ->set('content_blocks.upload.max_size', $config['upload']['max_size'])
            ->set('content_blocks.upload.allowed_mime_types', $config['upload']['allowed_mime_types']);

        // Opting into an upload dir switches the storage alias from the
        // default NullFileStorage to a LocalFileStorage rooted there.
        if ($config['upload']['directory'] !== null) {
            $container->services()
                ->set(\ContentBlocks\Storage\LocalFileStorage::class)
                ->args([$config['upload']['directory'], $config['upload']['public_prefix']]);
            $container->services()
                ->alias(\ContentBlocks\Storage\FileStorageInterface::class, \ContentBlocks\Storage\LocalFileStorage::class);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register the assets path so AssetMapper + StimulusBundle can discover the
        // controllers. Guarded: `framework.asset_mapper` is declared with
        // canBeEnabled(), whose normalization turns any non-empty array into
        // `enabled: true` — so prepending `paths` on a host that builds with Webpack
        // Encore (no symfony/asset-mapper installed, and we do not require it) would
        // *enable* the component and make FrameworkExtension throw at boot. Encore
        // hosts read the same controllers out of assets/package.json through
        // @symfony/stimulus-bridge instead; see docs/guide/installation.md.
        if (class_exists(AssetMapper::class)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $this->getPath() . '/assets' => '@klehm/content-blocks',
                    ],
                ],
            ]);
        }

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
        $container->addCompilerPass(new BlockFormExtensionPass());

        $container->registerAttributeForAutoconfiguration(
            AsContentBlock::class,
            static function (ChildDefinition $definition, AsContentBlock $attribute, \Reflector $reflector): void {
                $definition->addTag('content_blocks.block_type', ['priority' => $attribute->priority]);
            },
        );

        // Per-block form extensions: the attribute carries the targeted block
        // type ids + priority; BlockFormExtensionPass pairs each service with
        // its ids and feeds the collection (see BlockFormType).
        $container->registerAttributeForAutoconfiguration(
            AsBlockFormExtension::class,
            static function (ChildDefinition $definition, AsBlockFormExtension $attribute, \Reflector $reflector): void {
                $definition->addTag('content_blocks.block_form_extension', [
                    'priority' => $attribute->priority,
                    'block_types' => $attribute->blockTypes,
                ]);
            },
        );

        // Globally auto-tag host implementations of the section extension
        // points so they don't need any wiring beyond `autoconfigure: true`
        // on the host's services.yaml.
        $container->registerForAutoconfiguration(SectionStyleProviderInterface::class)
            ->addTag('content_blocks.section_style_provider');
        $container->registerForAutoconfiguration(ColorPaletteProviderInterface::class)
            ->addTag('content_blocks.color_palette_provider');
        $container->registerForAutoconfiguration(SectionDecoratorInterface::class)
            ->addTag('content_blocks.section_decorator');
        $container->registerForAutoconfiguration(SectionSettingsDefaultsProviderInterface::class)
            ->addTag('content_blocks.section_settings_defaults');
        $container->registerForAutoconfiguration(BlockDecoratorInterface::class)
            ->addTag('content_blocks.block_decorator');
        $container->registerForAutoconfiguration(\ContentBlocks\Versioning\EnvelopeUpgraderInterface::class)
            ->addTag('content_blocks.envelope_upgrader');

        $container->registerForAutoconfiguration(BlockDataDefaultsProviderInterface::class)
            ->addTag('content_blocks.block_data_defaults');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
