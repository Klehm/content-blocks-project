<?php

declare(strict_types=1);

namespace ContentBlocks\Service;

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
            }

            $copy->addColumn($columnCopy);
        }

        return $copy;
    }
}
