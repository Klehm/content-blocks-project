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
     * Semantic config — the declarative shortcut for what the provider
     * interfaces do in PHP. Both merge together in the registries.
     *
     * @see docs/guide/host-services.md
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('css_layer')
                    ->info('Serve the public stylesheets (layout, styling, the kit\'s) inside this cascade layer, e.g. "content-blocks", so any unlayered host rule wins over them whatever its specificity. Null keeps them unlayered.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn ($v): bool => !\is_string($v) || preg_match(PublicAsset\CssLayer::NAME_PATTERN, $v) !== 1)
                        ->thenInvalid('%s is not a CSS layer name: letters, digits, "-" and "_", dot-separated for a sub-layer.')
                    ->end()
                ->end()
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
                        ->arrayNode('layouts')
                            ->info('Column layouts offered by the add-section buttons, keyed by name and merged over the built-in full, two_cols and three_cols. Column spans are on a 12-unit grid; `name: false` hides a layout.')
                            ->useAttributeAsKey('name')
                            ->validate()
                                ->ifTrue(static fn (array $layouts): bool => array_filter(
                                    array_keys($layouts),
                                    static fn (int|string $name): bool => preg_match('/^[a-z][a-z0-9_]{0,29}$/', (string) $name) !== 1,
                                ) !== [])
                                ->thenInvalid('Section layout names must be lowercase snake_case, 30 characters at most (they are stored in cb_section.layout and used in a CSS class). Got %s.')
                            ->end()
                            ->arrayPrototype()
                                ->canBeDisabled()
                                ->children()
                                    ->scalarNode('label')
                                        ->info('Text or translation key (content_blocks domain). Required for a new layout.')
                                        ->defaultNull()
                                    ->end()
                                    ->enumNode('display')
                                        ->info('How a new section of this layout shows its columns: side by side (grid), scrolling (slider), one at a time (tabs) or as collapsible panels (accordion).')
                                        ->values(Section\SectionDisplay::ALL)
                                        ->defaultNull()
                                    ->end()
                                    ->arrayNode('columns')
                                        ->info('Span of each column, adding up to 12: [3, 3, 3, 3], [4, 8]. Required for a new layout.')
                                        ->integerPrototype()->min(1)->max(12)->end()
                                        ->validate()
                                            ->ifTrue(static fn (array $spans): bool => $spans !== [] && array_sum($spans) !== 12)
                                            ->thenInvalid('The column spans of a section layout must add up to 12, got %s.')
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->append($this->settingsNode(
                        'initial_settings',
                        'Settings written onto a section added from the builder, same shape as a preset\'s. Stored as the section\'s own values, so they render like anything the editor saved.',
                    ))
                ->end()
                ->arrayNode('picker')
                    ->info('Groups of the add-block picker. A block picks its group with #[AsContentBlock(category: ...)].')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('categories')
                            ->info('name: label (text or translation key, content_blocks domain), merged over the built-in text, media, layout, actions, advanced and other. Listed ones come first, in this order; `name: ~` moves a built-in and keeps its label.')
                            ->useAttributeAsKey('name')
                            ->scalarPrototype()->defaultNull()->end()
                            ->validate()
                                ->ifTrue(static fn (array $categories): bool => array_filter(
                                    array_keys($categories),
                                    static fn (int|string $name): bool => preg_match('/^[a-z][a-z0-9_]{0,29}$/', (string) $name) !== 1,
                                ) !== [])
                                ->thenInvalid('Picker category names must be lowercase snake_case, 30 characters at most. Got %s.')
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $categories): bool => array_filter(
                                    $categories,
                                    static fn (mixed $label, int|string $name): bool => ($label === null || $label === '')
                                        && !isset(BlockType\BlockCategoryRegistry::BUILT_IN[$name]),
                                    \ARRAY_FILTER_USE_BOTH,
                                ) !== [])
                                ->thenInvalid('A new picker category needs a label. Got %s.')
                            ->end()
                        ->end()
                        ->arrayNode('blocks')
                            ->info('block type: category, to move a block (a kit one, say) to another group than the one it declares.')
                            ->useAttributeAsKey('type')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $picker): bool => array_diff(
                            $picker['blocks'],
                            array_keys(BlockType\BlockCategoryRegistry::BUILT_IN + $picker['categories']),
                        ) !== [])
                        ->thenInvalid('content_blocks.picker.blocks names a category that is not declared: %s.')
                    ->end()
                ->end()
                ->arrayNode('structure')
                    ->info('What editors may do to sections and columns. Alias BuilderStructureResolverInterface to vary it by area.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('sections')
                            ->info('editable: add, move, duplicate, delete. fixed: the sections in place stay, their settings remain editable. hidden: no section in the UI, blocks stack one below the other.')
                            ->values(Builder\BuilderStructure::SECTIONS)
                            ->defaultValue(Builder\BuilderStructure::SECTIONS_EDITABLE)
                        ->end()
                        ->booleanNode('columns')
                            ->info('false: columns cannot be added, removed or named, and the Layout panel (display, widths) is gone.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ai')
                    ->info('AI actions. None shows without a service implementing ContentBlocks\\Ai\\AiProviderInterface; `ai: false` switches all of them off.')
                    ->addDefaultsIfNotSet()
                    ->treatFalseLike(['actions' => array_fill_keys(Ai\AiAction::ALL, false)])
                    ->children()
                        ->arrayNode('actions')
                            ->info('Each action on its own: rewrite, shorten, tone (text fields), alt_text (images), add (new sections from a prompt), edit_section, edit_page (rewrite what is there), apply (content-blocks:ai:apply).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('rewrite')->defaultTrue()->end()
                                ->booleanNode('shorten')->defaultTrue()->end()
                                ->booleanNode('tone')->defaultTrue()->end()
                                ->booleanNode('alt_text')->defaultTrue()->end()
                                ->booleanNode('add')->defaultTrue()->end()
                                ->booleanNode('edit_section')->defaultTrue()->end()
                                ->booleanNode('edit_page')->defaultTrue()->end()
                                ->booleanNode('apply')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('tones')
                            ->info('The tones the `tone` action offers. Empty switches it off.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(Ai\AiFeatures::DEFAULT_TONES)
                        ->end()
                        ->integerNode('max_sections')
                            ->info('The most sections one generation may write.')
                            ->min(1)
                            ->defaultValue(8)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('schema')
                    ->info('The content schema handed to a language model (content-blocks:schema).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('block_purposes')
                            ->info('block type: what it is for on this site, over the purpose the block type declares.')
                            ->useAttributeAsKey('type')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                        ->integerNode('examples')
                            ->info('How many saved section templates, latest first, the schema carries as examples.')
                            ->min(0)
                            ->defaultValue(3)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('styling')
                    ->info('The free styling fields (spacing, background, alignment) of the block and section sidebars. `styling: false` hides both; section_styles presets stay offered. UI only: stored values still render.')
                    ->addDefaultsIfNotSet()
                    ->treatFalseLike(['block' => false, 'section' => false])
                    ->treatTrueLike(['block' => true, 'section' => true])
                    ->children()
                        ->booleanNode('block')->defaultTrue()->end()
                        ->booleanNode('section')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('palette')
                    ->info('Named colors offered by the palette color picker.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('color')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('swatch')
                                ->info('Hex the builder shows for a colour it cannot read, e.g. var(--brand).')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('role')
                                ->info('What the colour is for, read by the content schema, e.g. "brand accent, call-to-action buttons only".')
                                ->defaultNull()
                            ->end()
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
                            ->scalarNode('role')
                                ->info('What the preset is for, read by the content schema, e.g. "dark highlighted section".')
                                ->defaultNull()
                            ->end()
                        ->end()
                        ->append($this->settingsNode(
                            'settings',
                            'Section settings applied by the preset (subset of a section\'s settings).',
                        ))
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
                                'application/pdf',
                            ])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('import')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_size')
                            ->info('Largest import file accepted, in bytes. PHP\'s upload_max_filesize and post_max_size cap it further.')
                            ->min(1)
                            ->defaultValue(52428800)
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Typed rather than a free-form variableNode, so preset YAML is validated
     * and self-documenting. Mirrors what the forms persist.
     *
     * @see docs/internals/rendering.md#style-presets-as-a-base-layer
     */
    private function settingsNode(string $name, string $info): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->info($info)
            // A host's own section field (a form type extension) is kept
            // as is; only the core keys are typed.
            ->ignoreExtraKeys(false)
            ->children()
                ->scalarNode('classes')->end()
                ->enumNode('display')->values(Section\SectionDisplay::ALL)->end()
                ->enumNode('displayTablet')->values(Section\SectionDisplay::ALL)->end()
                ->enumNode('displayMobile')->values(Section\SectionDisplay::ALL)->end()
                ->arrayNode('sliderPerView')
                    ->children()
                        ->integerNode('desktop')->min(1)->max(Section\SectionDisplay::MAX_PER_VIEW)->end()
                        ->integerNode('tablet')->min(1)->max(Section\SectionDisplay::MAX_PER_VIEW)->end()
                        ->integerNode('mobile')->min(1)->max(Section\SectionDisplay::MAX_PER_VIEW)->end()
                    ->end()
                ->end()
                ->enumNode('sliderControls')->values(Section\SectionDisplay::CONTROLS)->end()
                ->integerNode('sliderAutoplay')->min(0)->max(Section\SectionDisplay::MAX_AUTOPLAY)->end()
                ->booleanNode('sliderLoop')->end()
                ->enumNode('widthMode')->values(['full', 'centered'])->end()
                ->integerNode('maxWidth')->min(1)->end()
                ->scalarNode('columnWidths')->end()
                ->scalarNode('styleName')->end()
                ->booleanNode('stylingCustom')->end()
                ->booleanNode('reverseOnMobile')->end()
                ->booleanNode('accordionSingle')->end()
                ->booleanNode('accordionCollapsed')->end()
            ->end()
            ->append($this->stylingNode());
        self::refuseNearMisses($node);

        return $node;
    }

    /**
     * Extra keys are kept for host fields, but one a letter or two off a core
     * key is a typo, and would otherwise do nothing without a word.
     */
    private static function refuseNearMisses(ArrayNodeDefinition $node): void
    {
        $node->validate()->always(static function (mixed $value) use ($node): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            $core = array_keys($node->getChildNodeDefinitions());
            foreach (array_diff(array_keys($value), $core) as $key) {
                foreach ($core as $known) {
                    $distance = levenshtein(strtolower((string) $key), strtolower($known));
                    if ($distance > 0 && $distance <= (\strlen($known) > 4 ? 2 : 1)) {
                        throw new \InvalidArgumentException(sprintf('Unknown key "%s": did you mean "%s"?', $key, $known));
                    }
                }
            }

            return $value;
        })->end();
    }

    /**
     * The `styling` sub-tree. Leaves carry no defaults, so a preset holds only
     * the keys it explicitly sets.
     *
     * @see docs/internals/forms.md#the-styling-data-shape
     */
    private function stylingNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('styling');
        $node
            ->ignoreExtraKeys(false)
            ->append($this->responsiveBoxNode('padding'))
            ->append($this->responsiveBoxNode('margin'))
            ->append($this->responsiveGapNode())
            ->children()
                ->scalarNode('backgroundColor')->end()
                ->scalarNode('backgroundImage')->end()
                ->enumNode('backgroundSize')->values(['cover', 'contain'])->end()
                ->enumNode('backgroundPosition')->values([
                    'center', 'top', 'bottom', 'left', 'right',
                    'top left', 'top right', 'bottom left', 'bottom right',
                ])->end()
                ->scalarNode('overlayColor')->end()
                ->integerNode('overlayOpacity')->min(0)->max(100)->end()
                ->arrayNode('minHeight')
                    ->children()
                        ->integerNode('value')->min(0)->end()
                        ->enumNode('unit')->values(['px', 'vh'])->end()
                    ->end()
                ->end()
                ->enumNode('verticalAlign')->values(['start', 'center', 'end'])->end()
            ->end();

        self::refuseNearMisses($node);

        return $node;
    }

    /**
     * `linked` is UI state the decorators ignore, persisted only so the editor
     * can restore the toggle.
     *
     * @see docs/internals/forms.md#the-responsive-styling-sub-types
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

    /** A responsive single length in px, used for the column gap. */
    private function responsiveGapNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('gap');
        $children = $node->children();
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            $children->integerNode($viewport)->min(0)->end();
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // App-level parameter overrides still win: the merge pass re-applies
        // the app's parameters on top of extension-set ones.
        $container->parameters()
            ->set('content_blocks.content_version', $config['content_version'])
            ->set('content_blocks.css_layer', $config['css_layer'])
            ->set('content_blocks.section.default_width_mode', $config['section']['default_width_mode'])
            ->set('content_blocks.section.default_max_width', $config['section']['default_max_width'])
            ->set('content_blocks.section.initial_settings', $config['section']['initial_settings'] ?? [])
            ->set('content_blocks.section.layouts', Section\SectionLayoutRegistry::resolve($config['section']['layouts'] ?? []))
            ->set('content_blocks.picker.categories', BlockType\BlockCategoryRegistry::resolve($config['picker']['categories']))
            ->set('content_blocks.picker.blocks', $config['picker']['blocks'])
            ->set('content_blocks.structure.sections', $config['structure']['sections'])
            ->set('content_blocks.structure.columns', $config['structure']['columns'])
            ->set('content_blocks.ai.actions', $config['ai']['actions'])
            ->set('content_blocks.ai.tones', array_values($config['ai']['tones']))
            ->set('content_blocks.ai.max_sections', $config['ai']['max_sections'])
            ->set('content_blocks.schema.block_purposes', $config['schema']['block_purposes'])
            ->set('content_blocks.schema.examples', $config['schema']['examples'])
            ->set('content_blocks.styling.block', $config['styling']['block'])
            ->set('content_blocks.styling.section', $config['styling']['section'])
            ->set('content_blocks.palette', $config['palette'])
            ->set('content_blocks.section_styles', $config['section_styles'])
            ->set('content_blocks.upload.public_prefix', $config['upload']['public_prefix'])
            ->set('content_blocks.upload.max_size', $config['upload']['max_size'])
            ->set('content_blocks.upload.allowed_mime_types', $config['upload']['allowed_mime_types'])
            ->set('content_blocks.import.max_size', $config['import']['max_size']);

        // Opting into an upload dir switches the storage alias from the
        // default NullFileStorage to a LocalFileStorage rooted there.
        if ($config['upload']['directory'] !== null) {
            $container->services()
                ->set(Storage\LocalFileStorage::class)
                ->args([$config['upload']['directory'], $config['upload']['public_prefix']]);
            $container->services()
                ->alias(Storage\FileStorageInterface::class, Storage\LocalFileStorage::class);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Not optional: prepending `paths` without AssetMapper installed
        // enables it and throws. See bundle-boot.md#the-assetmapper-prepend
        if (class_exists(AssetMapper::class)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $this->getPath() . '/assets' => '@klehm/content-blocks',
                    ],
                ],
            ]);
        }

        // So `form_row(form.contentArea)` renders the builder out of the box.
        // See docs/internals/bundle-boot.md#the-other-two-prepends
        $builder->prependExtensionConfig('twig', [
            'form_themes' => [
                '@ContentBlocks/form/content_area_widget.html.twig',
            ],
        ]);

        // So cache:clear does not fail on a missing namespace right after
        // composer require. ux-twig-component is a hard dependency.
        $builder->prependExtensionConfig('twig_component', [
            'defaults' => [
                'ContentBlocks\\Twig\\Component\\' => '@ContentBlocks/components/',
            ],
        ]);
    }

    /**
     * A subclass of a kit block re-applies the attribute to be registered; it
     * keeps its parent's group unless it names one.
     */
    private static function inheritedCategory(\Reflector $class): ?string
    {
        $parent = $class instanceof \ReflectionClass ? $class->getParentClass() : false;
        for (; $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getAttributes(AsContentBlock::class) as $attribute) {
                $category = $attribute->newInstance()->category;
                if ($category !== null) {
                    return $category;
                }
            }
        }

        return null;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new BlockTypeCompilerPass());
        $container->addCompilerPass(new BlockFormExtensionPass());

        $container->registerAttributeForAutoconfiguration(
            AsContentBlock::class,
            static function (ChildDefinition $definition, AsContentBlock $attribute, \Reflector $reflector): void {
                $category = $attribute->category ?? self::inheritedCategory($reflector);
                $definition->addTag('content_blocks.block_type', array_filter(
                    ['priority' => $attribute->priority, 'category' => $category],
                    static fn (int|string|null $v): bool => $v !== null,
                ));
            },
        );

        // The attribute carries the targeted type ids and a priority;
        // BlockFormExtensionPass pairs each service with its ids.
        $container->registerAttributeForAutoconfiguration(
            AsBlockFormExtension::class,
            static function (ChildDefinition $definition, AsBlockFormExtension $attribute, \Reflector $reflector): void {
                $definition->addTag('content_blocks.block_form_extension', [
                    'priority' => $attribute->priority,
                    'block_types' => $attribute->blockTypes,
                ]);
            },
        );

        // Auto-tagged, so a host needs nothing beyond `autoconfigure: true`.
        // See docs/internals/bundle-boot.md#autoconfiguration
        $container->registerForAutoconfiguration(SectionStyleProviderInterface::class)
            ->addTag('content_blocks.section_style_provider');
        $container->registerForAutoconfiguration(ColorPaletteProviderInterface::class)
            ->addTag('content_blocks.color_palette_provider');
        $container->registerForAutoconfiguration(Icon\UiIconProviderInterface::class)
            ->addTag('content_blocks.ui_icon_provider');
        $container->registerForAutoconfiguration(SectionDecoratorInterface::class)
            ->addTag('content_blocks.section_decorator');
        $container->registerForAutoconfiguration(Schema\FieldSchemaContributorInterface::class)
            ->addTag('content_blocks.field_schema_contributor');
        $container->registerForAutoconfiguration(Ai\AiProviderInterface::class)
            ->addTag('content_blocks.ai_provider');
        $container->registerForAutoconfiguration(SectionSettingsDefaultsProviderInterface::class)
            ->addTag('content_blocks.section_settings_defaults');
        $container->registerForAutoconfiguration(BlockDecoratorInterface::class)
            ->addTag('content_blocks.block_decorator');
        $container->registerForAutoconfiguration(Versioning\EnvelopeUpgraderInterface::class)
            ->addTag('content_blocks.envelope_upgrader');

        $container->registerForAutoconfiguration(BlockDataDefaultsProviderInterface::class)
            ->addTag('content_blocks.block_data_defaults');

        // Priority is load-bearing here: the chain threads one payload
        // through every resolver. See bundle-boot.md#autoconfiguration
        $container->registerForAutoconfiguration(Rendering\BlockDataResolverInterface::class)
            ->addTag('content_blocks.block_data_resolver');
        $container->registerForAutoconfiguration(Headless\BlockJsonNormalizerInterface::class)
            ->addTag('content_blocks.block_json_normalizer');

        // Entries in the topbar's Actions menu, contributed by a bundle rather
        // than declared form by form.
        $container->registerForAutoconfiguration(Builder\BuilderActionProviderInterface::class)
            ->addTag('content_blocks.builder_action_provider');

        // The UI half of the seam above, so a bundle's dialog and script land
        // in the builder without a Stimulus controller or any host wiring.
        $container->registerForAutoconfiguration(Builder\BuilderShellExtensionInterface::class)
            ->addTag('content_blocks.builder_shell_extension');

        // Told which copy came from which source during a deep clone — the
        // seam for anything stored beside a block rather than inside its data.
        $container->registerForAutoconfiguration(Section\BlockCloneObserverInterface::class)
            ->addTag('content_blocks.block_clone_observer');

        $container->registerForAutoconfiguration(Section\ColumnCloneObserverInterface::class)
            ->addTag('content_blocks.column_clone_observer');
        $container->registerForAutoconfiguration(Rendering\ColumnSettingsResolverInterface::class)
            ->addTag('content_blocks.column_settings_resolver');

        // "These uploaded files are still referenced" — for a host keeping its
        // own images in the upload directory. See assets.md.
        $container->registerForAutoconfiguration(Asset\AssetReferenceProviderInterface::class)
            ->addTag('content_blocks.asset_reference_provider');

        // The export/import half of the clone seam above: rows a bundle keeps
        // beside a block, carried in the payload. See transfer.md.
        $container->registerForAutoconfiguration(Transfer\ContentAreaTransferExtensionInterface::class)
            ->addTag('content_blocks.transfer_extension');

        // A draft kept beside the area, which Publish would put live.
        $container->registerForAutoconfiguration(Publishing\UnpublishedChangesProviderInterface::class)
            ->addTag('content_blocks.unpublished_changes_provider');
        $container->registerForAutoconfiguration(Publishing\LocaleDraftsProviderInterface::class)
            ->addTag('content_blocks.locale_drafts_provider');

        // The template and clipboard half of the transfer seam above.
        $container->registerForAutoconfiguration(Snapshot\SnapshotExtensionInterface::class)
            ->addTag('content_blocks.snapshot_extension');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
