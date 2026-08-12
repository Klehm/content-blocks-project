<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * Default {@see SectionClonerInterface} — see it for the contract.
 *
 * Rationale for the draft-wins rule: the user's in-flight edit is more
 * representative of intent than the last published value.
 */
final class SectionCloner implements SectionClonerInterface
{
    /**
     * The observer collection is optional so that constructing a cloner by
     * hand — which tests and a host's own scripts do — stays a no-argument
     * call. The container always injects the real collection.
     */
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

                // Everything inside `data` is already copied above; this is how
                // anything stored *beside* the block (a satellite package's own
                // table) learns that a copy exists. See
                // BlockCloneObserverInterface — the copy has no id yet.
                $this->observers->blockCloned($block, $blockCopy);
            }

            $copy->addColumn($columnCopy);
        }

        return $copy;
    }
}
