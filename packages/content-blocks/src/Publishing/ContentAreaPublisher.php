<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Default {@see ContentAreaPublisherInterface} — see it for the contract.
 *
 * Cascade semantics:
 *  - A soft-deleted Section/Column triggers em->remove() on itself; Doctrine's
 *    ORM cascade={"remove"} mapping then wipes the descendant Columns/Blocks
 *    transparently. We don't iterate into them ourselves.
 *  - A non-deleted entity has its draft state promoted: position ← previewPosition,
 *    publishedData ← draftData (Block only), draftData ← null.
 *
 * Discard semantics — an entity never published is a brand-new addition and is
 * dropped entirely, everything else reverts to its last published state:
 *  - Section/Column with publishedAt === null (see Section::isPublished()) is
 *    removed; Doctrine's cascade wipes its descendants.
 *  - Block with publishedData === null is removed.
 *  - Other entities have their draft flags cleared.
 */
final class ContentAreaPublisher implements ContentAreaPublisherInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The context is accepted and deliberately unused: the core knows
     * nothing about locales, and an area's draft is one state to promote or
     * not. A decorator that does know — the i18n package's
     * `TranslationPublisher` — reads it before delegating here.
     */
    public function publish(ContentArea $area, ?PublishContext $context = null): void
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

    /** Accepts the context for the same reason {@see self::publish()} does. */
    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
    {
        // Before anything is removed: a block dragged into another column has
        // to go home first. The column it was dragged *into* may well be one
        // of the brand-new ones the loop below deletes, and a block still
        // sitting in that column would be cascaded away with it.
        $this->restoreMovedBlocks($area);

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

    /**
     * Puts every draft-moved block back in the column it is published in.
     *
     * The published column is stored as a bare id, so resolving it is this
     * class's job — it is already walking the whole area. A block whose
     * published column has since disappeared (its section was deleted and
     * published away in an earlier round) stays where it is: the move is all
     * that is left of it.
     */
    private function restoreMovedBlocks(ContentArea $area): void
    {
        $columns = [];
        $moved = [];

        foreach ($area->getSections() as $section) {
            foreach ($section->getColumns() as $column) {
                $columns[$column->getId()] = $column;
                foreach ($column->getBlocks() as $block) {
                    if ($block->getPublishedColumnId() !== null) {
                        $moved[] = $block;
                    }
                }
            }
        }

        foreach ($moved as $block) {
            $home = $columns[$block->getPublishedColumnId()] ?? null;
            if ($home === null) {
                $block->setPublishedColumnId(null);

                continue;
            }
            $block->restoreTo($home);
        }
    }
}
