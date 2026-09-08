<?php

declare(strict_types=1);

namespace ContentBlocks\Doctrine;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Touches `updatedAt` and stamps `contentVersion` on the owning ContentArea
 * whenever a descendant changes in the same flush.
 *
 * @see docs/internals/publishing.md#why-the-touch-listener-hooks-onflush
 */
final class ContentAreaTouchListener
{
    public function __construct(
        private readonly int $contentVersion = 1,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $touched = [];
        $now = new \DateTimeImmutable();

        $collect = function (object $entity) use (&$touched): void {
            $area = $this->resolveContentArea($entity);
            if ($area instanceof ContentArea) {
                $touched[\spl_object_id($area)] = $area;
            }
        };

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $collect($entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $collect($entity);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $collect($entity);
        }

        if ($touched === []) {
            return;
        }

        $meta = $em->getClassMetadata(ContentArea::class);

        foreach ($touched as $area) {
            // Touching an area already scheduled for deletion is pointless and
            // could re-add it to the update set.
            if ($uow->isScheduledForDelete($area)) {
                continue;
            }

            $area->setUpdatedAt($now);
            $area->setContentVersion($this->contentVersion);
            // A child change does not schedule the parent by itself, so
            // recompute to get the new updatedAt into this flush's SQL.
            $uow->recomputeSingleEntityChangeSet($meta, $area);
        }
    }

    private function resolveContentArea(object $entity): ?ContentArea
    {
        if ($entity instanceof ContentArea) {
            return $entity;
        }
        if ($entity instanceof Section) {
            return $entity->getContentArea();
        }
        if ($entity instanceof Column) {
            return $entity->getSection()?->getContentArea();
        }
        if ($entity instanceof Block) {
            return $entity->getColumn()?->getSection()?->getContentArea();
        }

        return null;
    }
}
