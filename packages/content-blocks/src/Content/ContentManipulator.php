<?php

declare(strict_types=1);

namespace ContentBlocks\Content;

use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\ViewportOrder;
use ContentBlocks\Section\BlockCloneObserverCollection;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Section\SectionClonerInterface;
use ContentBlocks\Section\SectionDisplay;
use ContentBlocks\Section\SectionLayoutRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Default {@see ContentManipulatorInterface}, and what the builder's
 * structural endpoints run.
 *
 * @see docs/internals/content-manipulation.md
 */
final class ContentManipulator implements ContentManipulatorInterface
{
    /** Past this, neither a grid nor a tab bar is usable. */
    public const MAX_COLUMNS = 20;

    private readonly SectionClonerInterface $cloner;

    private readonly BlockCloneObserverCollection $observers;

    /**
     * Without an entity manager nothing is persisted: new nodes then reach
     * the database through the cascade from their managed area.
     *
     * @param array<string, mixed> $initialSectionSettings
     */
    public function __construct(
        private readonly ?EntityManagerInterface $em,
        private readonly BlockTypeRegistry $blockTypes,
        private readonly SectionLayoutRegistry $sectionLayouts = new SectionLayoutRegistry(),
        private readonly array $initialSectionSettings = [],
        ?SectionClonerInterface $cloner = null,
        private readonly ?CollectionIdBackfiller $collectionIds = null,
        ?BlockCloneObserverCollection $observers = null,
    ) {
        $this->cloner = $cloner ?? new SectionCloner();
        $this->observers = $observers ?? new BlockCloneObserverCollection([]);
    }

    public function addSection(
        ContentArea $area,
        string $layout = Section::LAYOUT_FULL,
        array $settings = [],
        ?int $position = null,
    ): Section {
        $definition = $this->sectionLayouts->creatable($layout)
            ?? throw ContentManipulationException::unknownLayout($layout);

        $section = new Section();
        $section->setLayout($definition->name);

        $merged = $this->initialSectionSettings;
        if ($definition->display !== SectionDisplay::GRID) {
            $merged[SectionDisplay::SETTING] = $definition->display;
        }
        $merged = array_replace_recursive($merged, $settings);
        if ($merged !== []) {
            $section->setDraftSettings($merged);
        }

        foreach ($definition->presets() as $i => $preset) {
            $column = new Column();
            $column->setPreset($preset);
            $column->setPreviewPosition($i);
            $section->addColumn($column);
        }

        return $this->insertSection($area, $section, $position);
    }

    public function insertSection(ContentArea $area, Section $section, ?int $position = null): Section
    {
        if ($position === null) {
            $section->setPreviewPosition(DraftOrder::next($area->getSections()));
        } else {
            DraftOrder::insert(DraftOrder::sections($area), $section, $position);
        }

        $area->addSection($section);
        $this->em?->persist($section);

        return $section;
    }

    public function addColumn(Section $section): Column
    {
        if (\count(DraftOrder::columns($section)) >= self::MAX_COLUMNS) {
            throw ContentManipulationException::tooManyColumns(self::MAX_COLUMNS);
        }

        $column = new Column();
        $column->setPreviewPosition(DraftOrder::next($section->getColumns()));
        $section->addColumn($column);
        $this->em?->persist($column);

        self::rebalance($section);

        return $column;
    }

    public function addBlock(Column $column, string $type, array $data = [], ?int $position = null): Block
    {
        if (!$this->blockTypes->has($type)) {
            throw ContentManipulationException::unknownBlockType($type);
        }

        $data = array_replace($this->blockTypes->get($type)->getDefaultData(), $data);

        $block = new Block();
        $block->setType($type);
        // With ids from the start, a block never edited is translatable.
        $block->setDraftData($this->collectionIds?->backfill($type, $data) ?? $data);

        return $this->insertBlock($column, $block, $position);
    }

    public function insertBlock(Column $column, Block $block, ?int $position = null): Block
    {
        if ($position === null) {
            $block->setPreviewPosition(DraftOrder::next($column->getBlocks()));
        } else {
            DraftOrder::insert(DraftOrder::blocks($column), $block, $position);
        }

        $column->addBlock($block);
        $this->em?->persist($block);

        return $block;
    }

    public function moveSection(Section $section, int $position): void
    {
        $area = $section->getContentArea();
        if ($area === null || $section->isDeleted()) {
            return;
        }

        $siblings = DraftOrder::without(DraftOrder::sections($area), $section);
        DraftOrder::insert($siblings, $section, $position);
    }

    public function moveBlock(Block $block, Column $target, ?int $position = null): void
    {
        $source = $block->getColumn();
        $area = $source?->getSection()?->getContentArea();
        if ($area === null || !self::same($area, $target->getSection()?->getContentArea())) {
            throw ContentManipulationException::foreignTarget();
        }

        if ($source !== $target) {
            // getBlocks() is ordered by the *published* position; skipping
            // this would re-index an unpublished reorder back into it.
            DraftOrder::reindex(DraftOrder::without(DraftOrder::blocks($source), $block));

            // moveTo(), not setColumn(): the FK is the *draft* location, a
            // published block noting where PUBLIC keeps showing it.
            $block->moveTo($target);

            // A rank only means something among the siblings it was set in.
            $data = $block->getDraftData() ?? $block->getPublishedData();
            if (ViewportOrder::ranks($data) !== []) {
                $block->setDraftData(ViewportOrder::withoutRanks($data ?? []));
            }
        }

        $siblings = DraftOrder::without(DraftOrder::blocks($target), $block);
        DraftOrder::insert($siblings, $block, $position ?? \count($siblings));
    }

    public function duplicateSection(Section $section): Section
    {
        $copy = $this->cloner->cloneSection($section);

        $area = $section->getContentArea();
        if ($area === null) {
            return $copy;
        }

        return $this->insertSection($area, $copy, DraftOrder::after(DraftOrder::sections($area), $section));
    }

    public function duplicateBlock(Block $block): Block
    {
        $copy = new Block();
        $copy->setType($block->getType());
        $copy->setDraftData($block->getDraftData() ?? $block->getPublishedData() ?? []);

        $column = $block->getColumn();
        if ($column !== null) {
            $this->insertBlock($column, $copy, DraftOrder::after(DraftOrder::blocks($column), $block));
        }

        // As for a duplicated section: rows stored beside the block follow.
        $this->observers->blockCloned($block, $copy);

        return $copy;
    }

    public function deleteSection(Section $section): void
    {
        $section->setDeleted(true);
    }

    public function deleteColumn(Column $column): void
    {
        $section = $column->getSection();
        if ($column->isDeleted() || $section === null) {
            return;
        }

        // A section with no column would have nowhere to put a block.
        if (\count(DraftOrder::columns($section)) <= 1) {
            throw ContentManipulationException::lastColumn();
        }

        $column->setDeleted(true);
        self::rebalance($section);
    }

    public function deleteBlock(Block $block): void
    {
        $block->setDeleted(true);
    }

    public function restoreSection(Section $section): void
    {
        $section->setDeleted(false);
    }

    public function restoreBlock(Block $block): void
    {
        $block->setDeleted(false);
    }

    // Two loads of one row are one area; two unsaved areas are two.
    private static function same(ContentArea $area, ?ContentArea $other): bool
    {
        return $other === $area
            || ($other !== null && $area->getId() !== null && $other->getId() === $area->getId());
    }

    /**
     * Equal spans for the live columns: a count change resets an uneven
     * layout, and undo brings it back.
     */
    private static function rebalance(Section $section): void
    {
        $columns = DraftOrder::columns($section);
        $preset = 'col-' . max(1, intdiv(12, max(1, \count($columns))));
        foreach ($columns as $column) {
            if ($column->getPreset() !== $preset) {
                $column->setPreset($preset);
            }
        }
    }
}
