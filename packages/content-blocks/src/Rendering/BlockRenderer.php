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
 * Renders a ContentArea for the front-end.
 *
 * In PUBLIC mode: only published, non-deleted content, ordered by position.
 * In PREVIEW mode: draft data merged in, soft-deleted entities included with a
 * marker, ordered by previewPosition. The PREVIEW HTML also embeds the overlay
 * JS bridge so the parent admin window can react to user interactions.
 *
 * Mode is auto-detected from the current request: query `cb_preview=1`
 * combined with the AccessChecker's canEdit() granting access switches to
 * PREVIEW. Anything else falls through to PUBLIC.
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
        ]);
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
     * Pins the context's mode so everything downstream — the private walk and
     * every {@see BlockDataResolverInterface} — sees a concrete value rather
     * than "decide for me". $fallback is only invoked when the caller left the
     * mode open, which keeps the request-inspecting heuristic off the hot path
     * of the single-block and single-section entry points.
     */
    private function materialize(?RenderContext $context, callable $fallback): RenderContext
    {
        $context ??= new RenderContext();

        return $context->mode !== null ? $context : $context->withMode($fallback());
    }

    /**
     * Renders a single block's markup in isolation — the same
     * `block.html.twig` wrapper used inside a full area render, so the
     * fragment keeps its data-cb-block-id marker, decorators and view
     * template. Used by the builder to hot-swap one block in the preview
     * iframe without reloading the whole page.
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
     * Renders a single section's markup in isolation — the same
     * `section.html.twig` wrapper used inside a full area render. The builder
     * uses it to hot-reload a section's style (wrapper class/style + column
     * widths) after a settings change without reloading the whole page; it
     * only copies the wrapper attributes from this output, leaving the inner
     * blocks (and their JS state) untouched.
     */
    public function renderSection(Section $section, ?RenderContext $context = null): string
    {
        $context = $this->materialize($context, fn () => RenderMode::PREVIEW);

        return $this->twig->render(self::SECTION_TEMPLATE, [
            'section' => $this->buildSectionViewModel($section, $context),
            'isPreview' => $context->mode === RenderMode::PREVIEW,
        ]);
    }

    /**
     * @return list<array{id: ?int, layout: string, deleted: bool, columns: list<array<string, mixed>>}>
     */
    private function buildSectionTree(ContentArea $area, RenderContext $context): array
    {
        $sections = $area->getSections()->toArray();

        if ($context->mode === RenderMode::PUBLIC) {
            $sections = array_values(array_filter($sections, fn (Section $s) => !$s->isDeleted()));
            usort($sections, fn (Section $a, Section $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $out = [];
        foreach ($sections as $section) {
            $out[] = $this->buildSectionViewModel($section, $context);
        }

        return $out;
    }

    /**
     * Builds the template view-model for a single section. Shared by the full
     * area render (buildSectionTree) and the single-section render
     * (renderSection) so a hot-reloaded section is byte-for-byte identical to
     * its in-page form.
     *
     * @return array{id: ?int, layout: string, deleted: bool, extraClasses: string, inlineStyle: string, extraAttributes: array<string, string>, columns: list<array<string, mixed>>}
     */
    private function buildSectionViewModel(Section $section, RenderContext $context): array
    {
        $sectionDeleted = $section->isDeleted();
        $settings = $section->getEffectiveSettings(preferDraft: $context->mode === RenderMode::PREVIEW);
        // Style presets can carry settings values (padding, background…):
        // they apply as the base layer, the section's own saved settings win
        // key-by-key. With "Customize styling" off the saved settings hold
        // no styling subtree at all, so the preset applies untouched.
        $settings = $this->applyPresetSettings($settings);
        // Strip default-equal entries so the rendered markup stays clean: a
        // section saved with a framework-provided default won't get an
        // inline style for it, only user-overridden values do.
        $settings = $this->settingsDefaults->withoutDefaults($settings);
        $decoration = $this->sectionDecorators->decorate($settings, $section);

        return [
            'id' => $section->getId(),
            'layout' => $section->getLayout(),
            'deleted' => $sectionDeleted,
            'extraClasses' => $decoration->classString(),
            'inlineStyle' => $decoration->styleString(),
            'extraAttributes' => $decoration->attributes,
            'columns' => $this->buildColumnTree($section, $context, $sectionDeleted, $settings['columnWidths'] ?? null),
        ];
    }

    /**
     * Merges the selected style preset's settings (if any) underneath the
     * section's own settings — rightmost wins per key.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function applyPresetSettings(array $settings): array
    {
        $styleName = $settings['styleName'] ?? null;
        if (!\is_string($styleName) || $styleName === '') {
            return $settings;
        }

        $preset = $this->styleRegistry->get($styleName)?->settings ?? [];
        if ($preset === []) {
            return $settings;
        }

        return array_replace_recursive($preset, $settings);
    }

    /**
     * @param mixed $columnWidths Raw `columnWidths` section setting (a CSV
     *                            string like "40,60"), or null for equal
     *                            widths. Applied as per-column flex weights
     *                            only when it parses to exactly one positive
     *                            integer per column.
     *
     * @return list<array{id: ?int, preset: string, deleted: bool, width: ?int, blocks: list<array<string, mixed>>}>
     */
    private function buildColumnTree(Section $section, RenderContext $context, bool $parentDeleted, mixed $columnWidths = null): array
    {
        $columns = $section->getColumns()->toArray();

        if ($context->mode === RenderMode::PUBLIC) {
            $columns = array_values(array_filter($columns, fn (Column $c) => !$c->isDeleted()));
            usort($columns, fn (Column $a, Column $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($columns, fn (Column $a, Column $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $widths = self::parseColumnWidths($columnWidths, \count($columns));

        $out = [];
        foreach ($columns as $i => $column) {
            $columnDeleted = $parentDeleted || $column->isDeleted();
            $out[] = [
                'id' => $column->getId(),
                'preset' => $column->getPreset(),
                'deleted' => $columnDeleted,
                'width' => $widths[$i] ?? null,
                'blocks' => $this->buildBlockList($column, $context, $columnDeleted),
            ];
        }

        return $out;
    }

    /**
     * Parse the `columnWidths` setting into a positional list of integer
     * weights. Returns null (→ equal widths) unless the value is a CSV of
     * exactly $expected positive integers, so malformed or stale data falls
     * back to the clean preset-based layout.
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
     * @return list<array{id: ?int, type: string, data: array<string, mixed>, viewTemplate: ?string, deleted: bool}>
     */
    private function buildBlockList(Column $column, RenderContext $context, bool $parentDeleted): array
    {
        $blocks = $column->getBlocks()->toArray();

        if ($context->mode === RenderMode::PUBLIC) {
            $blocks = array_values(array_filter(
                $blocks,
                fn (Block $b) => !$b->isDeleted() && $b->getPublishedData() !== null,
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
     * Builds the template view-model for a single block. Shared by the full
     * area render (buildBlockList) and the single-block render (renderBlock)
     * so a hot-swapped block is byte-for-byte identical to its in-page form.
     *
     * @return array{id: ?int, type: string, data: array<string, mixed>, viewTemplate: ?string, deleted: bool, extraClasses: string, inlineStyle: string, extraAttributes: array<string, string>}
     */
    private function buildBlockViewModel(Block $block, RenderContext $context, bool $parentDeleted): array
    {
        $blockType = $this->blockTypeRegistry->has($block->getType())
            ? $this->blockTypeRegistry->get($block->getType())
            : null;

        // The draft-or-published rule lives in CoreBlockDataResolver, first in
        // the pipeline; anything a host registers afterwards refines what it
        // produced (translation being the motivating case). With no host
        // resolver the payload is exactly what this method used to compute
        // inline.
        $data = $this->blockDataResolvers->resolve($block, $context);

        // Strip default-equal entries so the rendered markup stays
        // clean: a block saved with the framework-provided default
        // (e.g. styling.backgroundColor=#ffffff) won't get an inline
        // style for it, only user-overridden values do. Decoration
        // sees the trimmed payload; the block type's view template
        // still receives the original $data.
        $decorationData = $this->blockDataDefaults->withoutDefaults($data);
        $decoration = $this->blockDecorators->decorate($decorationData, $block);

        return [
            'id' => $block->getId(),
            'type' => $block->getType(),
            'data' => $data,
            'viewTemplate' => $blockType?->getViewTemplate(),
            'deleted' => $parentDeleted || $block->isDeleted(),
            'extraClasses' => $decoration->classString(),
            'inlineStyle' => $decoration->styleString(),
            'extraAttributes' => $decoration->attributes,
        ];
    }
}
