<?php

declare(strict_types=1);

namespace ContentBlocks\Service;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promotes a ContentArea's draft state to published, or discards drafts.
 *
 * Cascade semantics:
 *  - A soft-deleted Section/Column triggers em->remove() on itself; Doctrine's
 *    ORM cascade={"remove"} mapping then wipes the descendant Columns/Blocks
 *    transparently. We don't iterate into them ourselves.
 *  - A non-deleted entity has its draft state promoted: position ← previewPosition,
 *    publishedData ← draftData (Block only), draftData ← null.
 *
 * Discard semantics:
 *  - Block with publishedData === null is treated as "newly added, never
 *    published" and removed.
 *  - Other entities have their draft flags cleared (revert to last published
 *    state).
 *  - Note: a Section/Column added but never published is NOT auto-removed in V1
 *    — we don't track a hasBeenPublished flag for those. To be revisited when
 *    the add-section flow lands in phase 3.
 */
final class ContentAreaPublisher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function publish(ContentArea $area): void
    {
        // Snapshot the collections to plain arrays — em->remove() during the
        // walk would otherwise mutate the underlying iteration.
        foreach ($area->getSections()->toArray() as $section) {
            if ($section->isDeleted()) {
                $this->em->remove($section);

                continue;
            }
            $section->publish();

            foreach ($section->getColumns()->toArray() as $column) {
                if ($column->isDeleted()) {
                    $this->em->remove($column);

                    continue;
                }
                $column->publish();

                foreach ($column->getBlocks()->toArray() as $block) {
                    if ($block->isDeleted()) {
                        $this->em->remove($block);

                        continue;
                    }
                    $block->publish();
                }
            }
        }

        $this->em->flush();
    }

    public function discardDraft(ContentArea $area): void
    {
        foreach ($area->getSections()->toArray() as $section) {
            // A section never published is a brand-new addition: drop it
            // entirely (Doctrine cascade removes its columns + blocks).
            if (!$section->isPublished()) {
                $this->em->remove($section);

                continue;
            }
            $section->revertDraft();

            foreach ($section->getColumns()->toArray() as $column) {
                if (!$column->isPublished()) {
                    $this->em->remove($column);

                    continue;
                }
                $column->revertDraft();

                foreach ($column->getBlocks()->toArray() as $block) {
                    if ($block->getPublishedData() === null) {
                        $this->em->remove($block);

                        continue;
                    }
                    $block->revertDraft();
                }
            }
        }

        $this->em->flush();
    }
}
