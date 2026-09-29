<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * A detached section from a stored payload: a section template, a clipboard
 * entry or one section of an import. It does not place the section.
 *
 * @internal
 *
 * @see docs/internals/content-manipulation.md#sections-from-a-payload
 */
final class RestoredSectionBuilder
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataKeys $dataKeys,
        private readonly ?CollectionIdBackfiller $collectionIds = null,
        private readonly SectionLayoutRegistry $layouts = new SectionLayoutRegistry(),
    ) {
    }

    /**
     * @param array<string, mixed>          $raw     layout, settings, columns
     * @param (\Closure(mixed): mixed)|null $rewrite for settings and data
     * @param array<string, Block>          $blocks  filled with ref => block
     */
    public function build(
        array $raw,
        BlockRestoreTally $tally,
        ?\Closure $rewrite = null,
        string $ref = 's0',
        array &$blocks = [],
    ): Section {
        $rewrite ??= static fn (mixed $value): mixed => $value;

        $section = new Section();
        $section->setLayout(RestoredStructure::layout($raw['layout'] ?? null, $this->layouts) ?? Section::LAYOUT_FULL);

        $settings = $raw['settings'] ?? null;
        if (\is_array($settings) && $settings !== []) {
            $settings = $rewrite($settings);
            if (\is_array($settings)) {
                /** @var array<string, mixed> $settings */
                $section->setDraftSettings($settings);
            }
        }

        $columns = $raw['columns'] ?? null;
        if (\is_array($columns)) {
            foreach (array_values($columns) as $i => $columnRaw) {
                if (!\is_array($columnRaw)) {
                    continue;
                }
                $column = $this->column($columnRaw, $tally, $rewrite, $ref . '.c' . $i, $blocks);
                $column->setPreviewPosition($i);
                $section->addColumn($column);
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed>     $raw
     * @param \Closure(mixed): mixed   $rewrite
     * @param array<string, Block>     $blocks
     */
    private function column(array $raw, BlockRestoreTally $tally, \Closure $rewrite, string $ref, array &$blocks): Column
    {
        $column = new Column();
        $preset = RestoredStructure::preset($raw['preset'] ?? null);
        if ($preset !== null) {
            $column->setPreset($preset);
        }
        $settings = ColumnSettings::sanitize($raw['settings'] ?? null);
        if ($settings !== []) {
            $column->setDraftSettings($settings);
        }

        $blocksRaw = $raw['blocks'] ?? null;
        if (!\is_array($blocksRaw)) {
            return $column;
        }

        // Positions count the *kept* blocks, so a skipped one leaves no hole;
        // refs count payload entries.
        $position = 0;
        foreach (array_values($blocksRaw) as $i => $blockRaw) {
            if (!\is_array($blockRaw)) {
                continue;
            }
            $block = $this->block($blockRaw, $tally, $rewrite);
            if ($block === null) {
                continue;
            }
            $block->setPreviewPosition($position++);
            $column->addBlock($block);
            $blocks[self::refOf($blockRaw, $ref . '.b' . $i)] = $block;
        }

        return $column;
    }

    /**
     * @param array<string, mixed>   $raw
     * @param \Closure(mixed): mixed $rewrite
     */
    private function block(array $raw, BlockRestoreTally $tally, \Closure $rewrite): ?Block
    {
        $type = $raw['type'] ?? null;
        if (!\is_string($type)) {
            return null;
        }
        if (!$this->registry->has($type)) {
            // Neither refused (a type this app lacks is expected from another
            // install) nor restored (it would leave an inert block).
            $tally->skip($type);

            return null;
        }

        $block = new Block();
        $block->setType($type);

        $data = $raw['data'] ?? null;
        if (\is_array($data)) {
            $tally->noteUnknownKeys($type, $this->dataKeys->unknownIn($type, $data));
            $data = $rewrite($data);
            if (\is_array($data)) {
                /** @var array<string, mixed> $data */
                // Kept verbatim, unknown keys included (they warn, never
                // drop). Ids carried stay: translations are keyed on them.
                $block->setDraftData($this->collectionIds?->backfill($type, $data) ?? $data);
            }
        }

        $tally->keep();

        return $block;
    }

    /**
     * The payload's own ref wins; the positional fallback is what an export
     * predating the key would have been given.
     *
     * @param array<string, mixed> $raw
     */
    private static function refOf(array $raw, string $fallback): string
    {
        $ref = $raw['ref'] ?? null;

        return \is_string($ref) && $ref !== '' ? $ref : $fallback;
    }
}
