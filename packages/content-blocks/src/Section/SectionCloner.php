<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * Default {@see SectionClonerInterface} — see it for the contract.
 *
 * @see docs/internals/rendering.md#cloning-a-section
 */
final class SectionCloner implements SectionClonerInterface
{
    /** Optional so building a cloner by hand stays a no-argument call. */
    private readonly BlockCloneObserverCollection $observers;

    public function __construct(?BlockCloneObserverCollection $observers = null)
    {
        $this->observers = $observers ?? new BlockCloneObserverCollection([]);
    }

    public function cloneSection(Section $source): Section
    {
        $copy = new Section();
        $copy->setLayout($source->getLayout());

        $sourceSettings = $source->getDraftSettings() ?? $source->getPublishedSettings();
        if ($sourceSettings !== null && $sourceSettings !== []) {
            $copy->setDraftSettings($sourceSettings);
        }

        foreach ($source->getColumns() as $column) {
            if ($column->isDeleted()) {
                continue;
            }

            $columnCopy = new Column();
            $columnCopy->setPreset($column->getPreset());
            $columnCopy->setPreviewPosition($column->getPreviewPosition());

            foreach ($column->getBlocks() as $block) {
                if ($block->isDeleted()) {
                    continue;
                }
                $blockCopy = new Block();
                $blockCopy->setType($block->getType());
                $blockCopy->setDraftData($block->getDraftData() ?? $block->getPublishedData() ?? []);
                $blockCopy->setPreviewPosition($block->getPreviewPosition());
                $columnCopy->addBlock($blockCopy);

                // How anything stored *beside* the block learns of the copy;
                // `data` was copied above. The copy has no id yet.
                $this->observers->blockCloned($block, $blockCopy);
            }

            $copy->addColumn($columnCopy);
        }

        return $copy;
    }
}
