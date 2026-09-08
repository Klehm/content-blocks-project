<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Default {@see ContentAreaPublisherInterface}. Removal rides Doctrine's
 * cascade rather than being walked here.
 *
 * @see docs/internals/publishing.md#publish-and-discard-semantics
 */
final class ContentAreaPublisher implements ContentAreaPublisherInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The context is accepted and deliberately unused — the core knows nothing
     * about locales. A decorator reads it before delegating here.
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
        // Before anything is removed: the column a block was dragged into may
        // be one the loop below deletes, cascading the block away with it.
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
     * Puts every draft-moved block back in its published column. One whose
     * published column has since gone stays where it is.
     *
     * @see docs/internals/publishing.md#publish-and-discard-semantics
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
