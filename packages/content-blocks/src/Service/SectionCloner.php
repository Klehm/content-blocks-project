<?php

declare(strict_types=1);

namespace ContentBlocks\Service;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * Deep-clones a Section (with its columns and non-deleted blocks) into a
 * detached copy. Used by both the section "duplicate" flow and the
 * area-level "replace with" flow.
 *
 * The returned section is unattached (no ContentArea, no positions assigned)
 * — the caller decides where to insert it and at which previewPosition. All
 * mutable state lands in the *draft* slots so the copy is born as an
 * unpublished change, mirroring the rest of the builder's lifecycle.
 *
 * Settings hierarchy: if the source has a draft, the copy carries that
 * draft (the user's in-flight edit is more representative of intent than
 * the last published value).
 */
final class SectionCloner
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
