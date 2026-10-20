<?php

declare(strict_types=1);

namespace ContentBlocks\Headless;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockDataResolverCollection;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\ColumnSettingsResolverCollection;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Rendering\RenderTree;
use ContentBlocks\Rendering\ViewportOrder;
use ContentBlocks\Section\ColumnSettings;
use ContentBlocks\Section\SectionDisplay;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The area as a tree of plain arrays: what the HTML render shows, minus the
 * markup. Soft-deleted nodes are left out in PREVIEW, as with `cb_chrome=0`.
 *
 * @see docs/internals/rendering.md#the-data-render
 */
final class ContentAreaNormalizer implements ContentAreaNormalizerInterface
{
    private const JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP
        | \JSON_HEX_APOS | \JSON_HEX_QUOT;

    private readonly RenderTree $tree;

    public function __construct(
        private readonly BlockRendererInterface $renderer,
        private readonly BlockDataResolverCollection $blockDataResolvers,
        private readonly BlockJsonNormalizerCollection $blockNormalizers = new BlockJsonNormalizerCollection(),
        private readonly ColumnSettingsResolverCollection $columnSettingsResolvers = new ColumnSettingsResolverCollection(),
        SectionStyleRegistry $styleRegistry = new SectionStyleRegistry([]),
        SectionSettingsDefaults $settingsDefaults = new SectionSettingsDefaults([]),
        private readonly RequestStack $requestStack = new RequestStack(),
    ) {
        $this->tree = new RenderTree($styleRegistry, $settingsDefaults);
    }

    public function normalize(ContentArea $area, ?RenderContext $context = null): array
    {
        $context = $this->materialize($area, $context);
        $sections = [];
        foreach ($this->walk($area, $context) as $section) {
            $sections[] = $this->section($section, $context);
        }

        return [
            'format' => self::FORMAT,
            'id' => $area->getId(),
            'mode' => $context->mode === RenderMode::PREVIEW ? 'preview' : 'public',
            'locale' => $this->viewContext($context)->locale,
            'contentVersion' => $area->getContentVersion(),
            'sections' => $sections,
        ];
    }

    public function normalizeSection(Section $section, ?RenderContext $context = null): ?array
    {
        $area = $section->getContentArea();
        if ($area === null) {
            return null;
        }
        $context = $this->materialize($area, $context);

        foreach ($this->walk($area, $context) as $node) {
            if ($node['section'] === $section) {
                return $this->section($node, $context);
            }
        }

        return null;
    }

    public function normalizeBlock(Block $block, ?RenderContext $context = null): ?array
    {
        $area = $block->getColumn()?->getSection()?->getContentArea();
        if ($area === null) {
            return null;
        }
        $context = $this->materialize($area, $context);

        foreach ($this->walk($area, $context) as $section) {
            foreach ($section['columns'] as $column) {
                foreach ($column['blocks'] as $node) {
                    if ($node['block'] === $block) {
                        return $this->block($node, $context);
                    }
                }
            }
        }

        return null;
    }

    public function toJson(ContentArea $area, ?RenderContext $context = null): string
    {
        $tree = $this->normalize($area, $context);
        foreach ($tree['sections'] as $s => $section) {
            $tree['sections'][$s]['settings'] = (object) $section['settings'];
            foreach ($section['columns'] as $c => $column) {
                foreach ($column['blocks'] as $b => $block) {
                    $tree['sections'][$s]['columns'][$c]['blocks'][$b]['data'] = (object) $block['data'];
                }
            }
        }

        return json_encode($tree, self::JSON_FLAGS);
    }

    private function materialize(ContentArea $area, ?RenderContext $context): RenderContext
    {
        $context ??= new RenderContext();

        return $context->mode !== null ? $context : $context->withMode($this->renderer->resolveMode($area));
    }

    /**
     * The nodes the mode shows, in its order, with each one's position per
     * viewport among its siblings.
     *
     * @return list<array{
     *     section: Section,
     *     order: array<string, int>,
     *     columns: list<array{
     *         column: Column,
     *         width: ?int,
     *         blocks: list<array{block: Block, order: array<string, int>}>,
     *     }>,
     * }>
     */
    private function walk(ContentArea $area, RenderContext $context): array
    {
        $mode = $context->mode ?? RenderMode::PUBLIC;
        $preview = $mode === RenderMode::PREVIEW;
        $buckets = $preview ? [] : $this->tree->publishedColumnBuckets($area);

        $sections = array_values(array_filter(
            $this->tree->sections($area, $mode),
            static fn (Section $s): bool => !($preview && $s->isDeleted()),
        ));
        $sectionOrder = ViewportOrder::positions($this->tree->ranks($sections, $mode));

        $out = [];
        foreach ($sections as $i => $section) {
            $columns = array_values(array_filter(
                $this->tree->columns($section, $mode),
                static fn (Column $c): bool => !($preview && $c->isDeleted()),
            ));
            $widths = RenderTree::columnWidths(
                $this->tree->sectionSettings($section, $mode)['columnWidths'] ?? null,
                \count($columns),
            );

            $columnNodes = [];
            foreach ($columns as $c => $column) {
                $blocks = array_values(array_filter(
                    $this->tree->blocks($column, $mode, $buckets),
                    static fn (Block $b): bool => !($preview && $b->isDeleted()),
                ));
                $blockOrder = ViewportOrder::positions($this->tree->ranks($blocks, $mode));
                $blockNodes = [];
                foreach ($blocks as $b => $block) {
                    $blockNodes[] = ['block' => $block, 'order' => $blockOrder[$b]];
                }
                $columnNodes[] = ['column' => $column, 'width' => $widths[$c] ?? null, 'blocks' => $blockNodes];
            }

            $out[] = ['section' => $section, 'order' => $sectionOrder[$i], 'columns' => $columnNodes];
        }

        return $out;
    }

    /**
     * @param array{
     *     section: Section,
     *     order: array<string, int>,
     *     columns: list<array{
     *         column: Column,
     *         width: ?int,
     *         blocks: list<array{block: Block, order: array<string, int>}>,
     *     }>,
     * } $node
     *
     * @return array<string, mixed>
     */
    private function section(array $node, RenderContext $context): array
    {
        $mode = $context->mode ?? RenderMode::PUBLIC;
        $section = $node['section'];
        $settings = ViewportOrder::withoutRanks($this->tree->sectionSettings($section, $mode));
        $displays = SectionDisplay::resolve($settings);

        $columns = [];
        foreach ($node['columns'] as $columnNode) {
            $column = $columnNode['column'];
            $preset = $column->getEffectivePreset($mode === RenderMode::PREVIEW);
            $columnSettings = $this->columnSettingsResolvers->resolve(
                $column,
                $context,
                $column->getEffectiveSettings($mode === RenderMode::PREVIEW),
            );
            $blocks = [];
            foreach ($columnNode['blocks'] as $blockNode) {
                $blocks[] = $this->block($blockNode, $context);
            }
            $columns[] = [
                'id' => $column->getId(),
                'preset' => $preset,
                'span' => preg_match('/^col-(\d+)$/', $preset, $m) === 1 ? (int) $m[1] : null,
                'width' => $columnNode['width'],
                'label' => ColumnSettings::label($columnSettings),
                'blocks' => $blocks,
            ];
        }

        return [
            'id' => $section->getId(),
            'layout' => $section->getLayout(),
            'display' => $displays,
            'accordion' => \in_array(SectionDisplay::ACCORDION, $displays, true)
                ? SectionDisplay::accordionOptions($settings)
                : null,
            'slider' => \in_array(SectionDisplay::SLIDER, $displays, true)
                ? SectionDisplay::sliderOptions($settings) + ['perView' => SectionDisplay::perView($settings)]
                : null,
            'order' => self::order($node['order']),
            'settings' => $settings,
            'columns' => $columns,
        ];
    }

    /**
     * @param array{block: Block, order: array<string, int>} $node
     *
     * @return array{
     *     id: ?int,
     *     type: string,
     *     data: array<string, mixed>,
     *     order: array{tablet: ?int, mobile: ?int},
     * }
     */
    private function block(array $node, RenderContext $context): array
    {
        $block = $node['block'];
        $view = new BlockView($block->getId(), $block->getType());
        $data = ViewportOrder::withoutRanks($this->blockDataResolvers->resolve($block, $context));

        return [
            'id' => $block->getId(),
            'type' => $block->getType(),
            'data' => $this->blockNormalizers->normalize($view, $data, $this->viewContext($context)),
            'order' => self::order($node['order']),
        ];
    }

    /**
     * Null on a viewport means "the desktop order".
     *
     * @param array<string, int> $positions
     *
     * @return array{tablet: ?int, mobile: ?int}
     */
    private static function order(array $positions): array
    {
        return [
            'tablet' => $positions['tablet'] ?? null,
            'mobile' => $positions['mobile'] ?? null,
        ];
    }

    /** The locale the page is rendered in, as the HTML render decides it. */
    private function viewContext(RenderContext $context): RenderContext
    {
        if ($context->locale !== null) {
            return $context;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        return $locale === null ? $context : $context->withLocale($locale);
    }
}
