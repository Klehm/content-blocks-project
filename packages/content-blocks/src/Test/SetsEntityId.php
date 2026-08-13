<?php

declare(strict_types=1);

namespace ContentBlocks\Test;

/**
 * Writes the private, database-generated `$id` of a ContentBlocks entity.
 *
 * Every test that needs an identified entity without a database reaches for
 * reflection; this keeps the one reflective write in a single place rather
 * than in each suite that wants it.
 *
 * @internal to the Test namespace — the builders are the surface, not this.
 */
trait SetsEntityId
{
    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}
