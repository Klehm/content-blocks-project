<?php

declare(strict_types=1);

namespace ContentBlocks\Content;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Builds and rearranges an area's content from code, as the builder does:
 * draft only, positions kept, nothing flushed. Call `publish()` to go live.
 *
 * @see docs/guide/content-from-code.md
 */
interface ContentManipulatorInterface
{
    /**
     * A section with its layout's columns and the configured initial settings,
     * `$settings` merged over them. Appended when `$position` is null.
     *
     * @param array<string, mixed> $settings
     *
     * @throws ContentManipulationException unknown or disabled layout
     */
    public function addSection(
        ContentArea $area,
        string $layout = Section::LAYOUT_FULL,
        array $settings = [],
        ?int $position = null,
    ): Section;

    /**
     * Places a section built elsewhere (a clone, a template, an import), not
     * yet in an area. Appended when `$position` is null.
     */
    public function insertSection(ContentArea $area, Section $section, ?int $position = null): Section;

    /**
     * Appends a column and gives every live column an equal span.
     *
     * @throws ContentManipulationException past the column limit
     */
    public function addColumn(Section $section): Column;

    /**
     * A block of `$type`, `$data` merged over the type's defaults, collection
     * entries given their `_id`. Appended when `$position` is null.
     *
     * @param array<string, mixed> $data
     *
     * @throws ContentManipulationException unknown block type
     */
    public function addBlock(Column $column, string $type, array $data = [], ?int $position = null): Block;

    /**
     * Places a block built elsewhere, not yet in a column. Appended when
     * `$position` is null.
     */
    public function insertBlock(Column $column, Block $block, ?int $position = null): Block;

    /** Moves a section among its live siblings, clamped to the list. */
    public function moveSection(Section $section, int $position): void;

    /**
     * Moves a block into a column of the same area, at the end when
     * `$position` is null.
     *
     * @throws ContentManipulationException target in another area
     */
    public function moveBlock(Block $block, Column $target, ?int $position = null): void;

    /** A copy inserted right after the source, translations included. */
    public function duplicateSection(Section $section): Section;

    /** A copy inserted right after the source, translations included. */
    public function duplicateBlock(Block $block): Block;

    /**
     * Soft deletes: the public page keeps the element until `publish()`,
     * `discardDraft()` or a restore brings it back.
     */
    public function deleteSection(Section $section): void;

    /**
     * Also re-spans the remaining columns equally.
     *
     * @throws ContentManipulationException the section's last live column
     */
    public function deleteColumn(Column $column): void;

    public function deleteBlock(Block $block): void;

    public function restoreSection(Section $section): void;

    public function restoreBlock(Block $block): void;
}
