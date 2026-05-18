<?php

declare(strict_types=1);

namespace ContentBlocks\Doctrine;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Touches ContentArea::updatedAt whenever a child Section / Column / Block is
 * inserted, updated, or removed in the same flush.
 *
 * ContentArea itself rarely gets its own field mutated (the entity only has
 * id + the children collection), so a @PreUpdate on ContentArea would never
 * fire on a typical block edit. Listening on onFlush lets us watch every
 * relevant child entity and bubble the change up to the owning area.
 *
 * Registered via the `doctrine.event_listener` service tag in services.php
 * (event = "onFlush") so the package does not need to declare a hard
 * dependency on DoctrineBundle's #[AsDoctrineListener] attribute.
 */
final class ContentAreaTouchListener
{
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
            // Skip areas about to be deleted in this same flush — touching
            // them would be pointless and could re-add them to the
            // updates set.
            if ($uow->isScheduledForDelete($area)) {
                continue;
            }

            $area->setUpdatedAt($now);
            // ContentArea may not be in any change-tracking list yet (a child
            // change doesn't put the parent in scheduled updates by itself).
            // recomputeSingleEntityChangeSet picks up the new updatedAt so
            // it lands in the SQL emitted by this flush.
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
