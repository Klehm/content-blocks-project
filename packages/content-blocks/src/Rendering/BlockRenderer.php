<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Security\AccessCheckerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders a ContentArea for the front-end. PUBLIC serves the published state
 * and nothing else; PREVIEW merges the draft in.
 *
 * @see docs/internals/rendering.md#what-each-mode-renders
 */
final class BlockRenderer implements BlockRendererInterface
{
    private const RENDER_TEMPLATE = '@ContentBlocks/render/content_area.html.twig';
    private const BLOCK_TEMPLATE = '@ContentBlocks/render/block.html.twig';
    private const SECTION_TEMPLATE = '@ContentBlocks/render/section.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly SectionDecoratorCollection $sectionDecorators,
        private readonly SectionSettingsDefaults $settingsDefaults,
        private readonly SectionStyleRegistry $styleRegistry,
        private readonly TranslatorInterface $translator,
        private readonly \ContentBlocks\Block\BlockDecoratorCollection $blockDecorators,
        private readonly \ContentBlocks\Block\BlockDataDefaults $blockDataDefaults,
        private readonly BlockDataResolverCollection $blockDataResolvers,
    ) {
    }

    public function render(ContentArea $area, ?RenderContext $context = null): string
    {
        $context = $this->materialize($context, fn () => $this->resolveMode($area));
        $mode = $context->mode;
        $sections = $this->buildSectionTree($area, $context);

        $blockTypes = [];
        if ($mode === RenderMode::PREVIEW) {
            foreach ($this->blockTypeRegistry->all() as $type => $blockType) {
                $label = $blockType::getLabel();
                $blockTypes[] = [
                    'type' => $type,
                    'label' => $label instanceof TranslatableInterface
                        ? $label->trans($this->translator)
                        : $this->translator->trans((string) $label),
                    // Inline SVG markup or null; the overlay supplies a
                    // generic fallback glyph when null.
                    'icon' => $blockType::getIcon(),
                ];
            }
        }

        return $this->twig->render(self::RENDER_TEMPLATE, [
            'mode' => $mode,
            'sections' => $sections,
            'blockTypes' => $blockTypes,
            'chrome' => $this->chromeEnabled($mode),
        ]);
    }

    /**
     * Only ever true in PREVIEW, where it is opt-out via
     * {@see BlockRendererInterface::CHROME_QUERY_PARAM}.
     *
     * @see docs/internals/rendering.md#why-chrome-is-a-separate-flag-from-mode
     */
    private function chromeEnabled(?RenderMode $mode): bool
    {
        if ($mode !== RenderMode::PREVIEW) {
            return false;
        }

        return $this->requestStack->getCurrentRequest()?->query->get(self::CHROME_QUERY_PARAM) !== '0';
    }

    public function resolveMode(ContentArea $area): RenderMode
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return RenderMode::PUBLIC;
        }

        if ($request->query->get(self::QUERY_PARAM) !== '1') {
            return RenderMode::PUBLIC;
        }

        if (!$this->accessChecker->canEdit($area)) {
            return RenderMode::PUBLIC;
        }

        return RenderMode::PREVIEW;
    }

    /**
     * Pins the mode so everything downstream sees a concrete value. $fallback
     * runs only when the caller left it open.
     *
     * @see docs/internals/rendering.md#why-the-pipeline-takes-a-context-object
     */
    private function materialize(?RenderContext $context, callable $fallback): RenderContext
    {
        $context ??= new RenderContext();

        return $context->mode !== null ? $context : $context->withMode($fallback());
    }

    /**
     * One block in isolation, through the same wrapper a full area render
     * uses, so the builder can hot-swap it byte-for-byte.
     *
     * @see docs/internals/rendering.md#replacing-the-renderer-itself
     */
    public function renderBlock(Block $block, ?RenderContext $context = null): string
    {
        $context = $this->materialize($context, fn () => RenderMode::PREVIEW);

        return $this->twig->render(self::BLOCK_TEMPLATE, [
            'block' => $this->buildBlockViewModel($block, $context, false),
            'isPreview' => $context->mode === RenderMode::PREVIEW,
        ]);
    }

    /**
     * One section in isolation. The builder copies only the wrapper
     * attributes back, leaving the inner blocks and their JS state alone.
     *
     * @see docs/internals/rendering.md#replacing-the-renderer-itself
     */
    public function renderSection(Section $section, ?RenderContext $context = null): string
    {
        $context = $this->materialize($context, fn () => RenderMode::PREVIEW);

        return $this->twig->render(self::SECTION_TEMPLATE, [
            'section' => $this->buildSectionViewModel($section, $context),
            'isPreview' => $context->mode === RenderMode::PREVIEW,
            'chrome' => $this->chromeEnabled($context->mode),
        ]);
    }

    /**
     * @return list<array{
     *     id: ?int,
     *     layout: string,
     *     deleted: bool,
     *     columns: list<array<string, mixed>>,
     * }>
     */
    private function buildSectionTree(ContentArea $area, RenderContext $context): array
    {
        $sections = $area->getSections()->toArray();

        if ($context->mode === RenderMode::PUBLIC) {
            $sections = array_values(array_filter($sections, fn (Section $s) => $s->isPublished()));
            usort($sections, fn (Section $a, Section $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $buckets = $context->mode === RenderMode::PUBLIC ? $this->publishedColumnBuckets($area) : [];

        $out = [];
        foreach ($sections as $section) {
            $out[] = $this->buildSectionViewModel($section, $context, $buckets);
        }

        return $out;
    }

    /**
     * Where the public page puts each block, keyed by column id. `[]` when no
     * block carries a published column, the common case.
     *
     * @see docs/internals/rendering.md#the-deleted-flag-is-draft-only
     *
     * @return array<int, list<Block>>
     */
    private function publishedColumnBuckets(ContentArea $area): array
    {
        $moved = false;
        $buckets = [];

        foreach ($area->getSections() as $section) {
            foreach ($section->getColumns() as $column) {
                $columnId = $column->getId();
                if ($columnId === null) {
                    // Not persisted, so nothing published can live there.
                    continue;
                }

                $buckets[$columnId] ??= [];
                foreach ($column->getBlocks() as $block) {
                    $home = $block->getPublishedColumnId();
                    if ($home === null) {
                        $home = $columnId;
                    } else {
                        $moved = true;
                    }
                    $buckets[$home][] = $block;
                }
            }
        }

        return $moved ? $buckets : [];
    }

    /**
     * Builds the template view-model for a single section, shared by the area
     * render and renderSection so a hot-reloaded section is byte-identical.
     *
     * @param array<int, list<Block>>|null $buckets
     *
     * @return array{
     *     id: ?int,
     *     layout: string,
     *     deleted: bool,
     *     extraClasses: string,
     *     inlineStyle: string,
     *     extraAttributes: array<string, string>,
     *     columns: list<array<string, mixed>>,
     * }
     */
    private function buildSectionViewModel(Section $section, RenderContext $context, ?array $buckets = null): array
    {
        // Standalone entry point (renderSection): resolve the area's moved
        // blocks ourselves. Inside a full area render the caller already did.
        if ($buckets === null) {
            $area = $context->mode === RenderMode::PUBLIC ? $section->getContentArea() : null;
            $buckets = $area !== null ? $this->publishedColumnBuckets($area) : [];
        }

        // `deleted` is a draft flag and the templates prune a flagged subtree
        // whenever they render chromeless — in PUBLIC that would be the leak.
        $sectionDeleted = $context->mode === RenderMode::PREVIEW && $section->isDeleted();
        $settings = $section->getEffectiveSettings(preferDraft: $context->mode === RenderMode::PREVIEW);
        // Preset settings are the base layer; the section's own win key by
        // key. See docs/internals/rendering.md#style-presets-as-a-base-layer
        $settings = $this->applyPresetSettings($settings);
        // Only real overrides reach the markup.
        $settings = $this->settingsDefaults->withoutDefaults($settings);
        $decoration = $this->sectionDecorators->decorate($settings, $section);

        return [
            'id' => $section->getId(),
            'layout' => $section->getLayout(),
            'deleted' => $sectionDeleted,
            'extraClasses' => $decoration->classString(),
            'inlineStyle' => $decoration->styleString(),
            'extraAttributes' => $decoration->attributes,
            'columns' => $this->buildColumnTree($section, $context, $sectionDeleted, $settings['columnWidths'] ?? null, $buckets),
        ];
    }

    /**
     * Merges the selected style preset's settings (if any) underneath the
     * section's own settings — rightmost wins per key.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function applyPresetSettings(array $settings): array
    {
        $styleName = $settings['styleName'] ?? null;
        if (!\is_string($styleName) || $styleName === '') {
            return $settings;
        }

        $preset = $this->styleRegistry->get($styleName)->settings ?? [];
        if ($preset === []) {
            return $settings;
        }

        return array_replace_recursive($preset, $settings);
    }

    /**
     * @param mixed                  $columnWidths Raw `columnWidths` setting: a
     *      CSV like "40,60", or null for equal widths. Applied as flex weights
     *      only when it parses to one positive integer per column.
     * @param array<int, list<Block>> $buckets
     *
     * @return list<array{
     *     id: ?int,
     *     preset: string,
     *     deleted: bool,
     *     width: ?int,
     *     blocks: list<array<string, mixed>>,
     * }>
     */
    private function buildColumnTree(Section $section, RenderContext $context, bool $parentDeleted, mixed $columnWidths = null, array $buckets = []): array
    {
        $columns = $section->getColumns()->toArray();

        if ($context->mode === RenderMode::PUBLIC) {
            $columns = array_values(array_filter($columns, fn (Column $c) => $c->isPublished()));
            usort($columns, fn (Column $a, Column $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($columns, fn (Column $a, Column $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $widths = self::parseColumnWidths($columnWidths, \count($columns));

        $out = [];
        foreach ($columns as $i => $column) {
            $columnDeleted = $parentDeleted || ($context->mode === RenderMode::PREVIEW && $column->isDeleted());
            $out[] = [
                'id' => $column->getId(),
                'preset' => $column->getPreset(),
                'deleted' => $columnDeleted,
                'width' => $widths[$i] ?? null,
                'blocks' => $this->buildBlockList($column, $context, $columnDeleted, $buckets),
            ];
        }

        return $out;
    }

    /**
     * Null (→ equal widths) unless the value is a CSV of exactly $expected
     * positive integers, so stale data falls back to the preset layout.
     *
     * @return list<int>|null
     */
    private static function parseColumnWidths(mixed $value, int $expected): ?array
    {
        if (!\is_string($value) || $value === '' || $expected < 2) {
            return null;
        }

        $parts = explode(',', $value);
        if (\count($parts) !== $expected) {
            return null;
        }

        $widths = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                return null;
            }
            $n = (int) $part;
            if ($n < 1) {
                return null;
            }
            $widths[] = $n;
        }

        return $widths;
    }

    /**
     * @param array<int, list<Block>> $buckets
     *
     * @return list<array{
     *     id: ?int,
     *     type: string,
     *     data: array<string, mixed>,
     *     viewTemplate: ?string,
     *     deleted: bool,
     * }>
     */
    private function buildBlockList(Column $column, RenderContext $context, bool $parentDeleted, array $buckets = []): array
    {
        $blocks = $buckets === [] ? $column->getBlocks()->toArray() : ($buckets[$column->getId()] ?? []);

        if ($context->mode === RenderMode::PUBLIC) {
            $blocks = array_values(array_filter(
                $blocks,
                fn (Block $b) => $b->getPublishedData() !== null,
            ));
            usort($blocks, fn (Block $a, Block $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($blocks, fn (Block $a, Block $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $out = [];
        foreach ($blocks as $block) {
            $out[] = $this->buildBlockViewModel($block, $context, $parentDeleted);
        }

        return $out;
    }

    /**
     * Shared by the full area render and the single-block one, so a
     * hot-swapped block is byte-for-byte its in-page form.
     *
     * @return array{
     *     id: ?int,
     *     type: string,
     *     data: array<string, mixed>,
     *     viewTemplate: ?string,
     *     deleted: bool,
     *     extraClasses: string,
     *     inlineStyle: string,
     *     extraAttributes: array<string, string>,
     * }
     */
    private function buildBlockViewModel(Block $block, RenderContext $context, bool $parentDeleted): array
    {
        $blockType = $this->blockTypeRegistry->has($block->getType())
            ? $this->blockTypeRegistry->get($block->getType())
            : null;

        // The draft-or-published rule lives in CoreBlockDataResolver, first in
        // the pipeline; a host resolver refines what it produced.
        $data = $this->blockDataResolvers->resolve($block, $context);

        // Decoration sees the trimmed payload; the block type's view
        // template still receives the original $data.
        $decorationData = $this->blockDataDefaults->withoutDefaults($data);
        $decoration = $this->blockDecorators->decorate($decorationData, $block);

        return [
            'id' => $block->getId(),
            'type' => $block->getType(),
            'data' => $data,
            'viewTemplate' => $blockType?->getViewTemplate(),
            'deleted' => $parentDeleted || ($context->mode === RenderMode::PREVIEW && $block->isDeleted()),
            'extraClasses' => $decoration->classString(),
            'inlineStyle' => $decoration->styleString(),
            'extraAttributes' => $decoration->attributes,
        ];
    }
}
