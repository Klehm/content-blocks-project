<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;

/**
 * Which sections, columns and blocks a mode renders, in which order, with which
 * settings: the one rule the HTML renderer and the normalizer both read.
 *
 * @see docs/internals/rendering.md#what-each-mode-renders
 *
 * @internal
 */
final class RenderTree
{
    public function __construct(
        private readonly SectionStyleRegistry $styleRegistry = new SectionStyleRegistry([]),
        private readonly SectionSettingsDefaults $settingsDefaults = new SectionSettingsDefaults([]),
    ) {
    }

    /** @return list<Section> */
    public function sections(ContentArea $area, RenderMode $mode): array
    {
        $sections = $area->getSections()->toArray();

        if ($mode === RenderMode::PUBLIC) {
            $sections = array_values(array_filter($sections, fn (Section $s) => $s->isPublished()));
            usort($sections, fn (Section $a, Section $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        return $sections;
    }

    /** @return list<Column> */
    public function columns(Section $section, RenderMode $mode): array
    {
        $columns = $section->getColumns()->toArray();

        if ($mode === RenderMode::PUBLIC) {
            $columns = array_values(array_filter($columns, fn (Column $c) => $c->isPublished()));
            usort($columns, fn (Column $a, Column $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($columns, fn (Column $a, Column $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        return $columns;
    }

    /**
     * @param array<int, list<Block>>|null $buckets computed here when null,
     *      see {@see publishedColumnBuckets()}
     *
     * @return list<Block>
     */
    public function blocks(Column $column, RenderMode $mode, ?array $buckets = null): array
    {
        if ($buckets === null) {
            $area = $mode === RenderMode::PUBLIC ? $column->getSection()?->getContentArea() : null;
            $buckets = $area !== null ? $this->publishedColumnBuckets($area) : [];
        }
        $blocks = $buckets === [] ? $column->getBlocks()->toArray() : ($buckets[$column->getId()] ?? []);

        if ($mode === RenderMode::PUBLIC) {
            $blocks = array_values(array_filter(
                $blocks,
                fn (Block $b) => $b->getPublishedData() !== null,
            ));
            usort($blocks, fn (Block $a, Block $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($blocks, fn (Block $a, Block $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        return $blocks;
    }

    /**
     * Where the public page puts each block, keyed by column id. `[]` when no
     * block carries a published column, the common case.
     *
     * @see docs/internals/rendering.md#the-deleted-flag-is-draft-only
     *
     * @return array<int, list<Block>>
     */
    public function publishedColumnBuckets(ContentArea $area): array
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
     * The settings a mode reads, its style preset merged underneath key by
     * key, default-equal entries stripped.
     *
     * @see docs/internals/rendering.md#style-presets-as-a-base-layer
     *
     * @return array<string, mixed>
     */
    public function sectionSettings(Section $section, RenderMode $mode): array
    {
        $settings = $section->getEffectiveSettings(preferDraft: $mode === RenderMode::PREVIEW);

        $styleName = $settings['styleName'] ?? null;
        if (\is_string($styleName) && $styleName !== '') {
            $preset = $this->styleRegistry->get($styleName)->settings ?? [];
            if ($preset !== []) {
                $settings = array_replace_recursive($preset, $settings);
            }
        }

        return $this->settingsDefaults->withoutDefaults($settings);
    }

    /**
     * The ranks per viewport of each sibling, as {@see ViewportOrder::ranks()}.
     *
     * @param list<Section>|list<Block> $siblings in rendered order
     *
     * @return list<array<string, int>>
     */
    public function ranks(array $siblings, RenderMode $mode): array
    {
        $preview = $mode === RenderMode::PREVIEW;

        return array_map(
            static fn (Section|Block $e): array => ViewportOrder::ranks(match (true) {
                $e instanceof Section => $e->getEffectiveSettings(preferDraft: $preview),
                $preview => $e->getDraftData() ?? $e->getPublishedData(),
                default => $e->getPublishedData(),
            }),
            $siblings,
        );
    }

    /**
     * Null (→ equal widths) unless the value is a CSV of exactly $expected
     * positive integers, so stale data falls back to the preset layout.
     *
     * @return list<int>|null
     */
    public static function columnWidths(mixed $value, int $expected): ?array
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
}
