<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the fields {@see AreaStateSnapshot} records, so a stored
 * op and a live entity are compared and assigned in one place.
 *
 * @see docs/internals/history.md#an-entry-is-applied-or-refused
 *
 * @internal
 */
final class StateApplier
{
    /** Assigned first: it decides which collections the rest lands in. */
    private const COLUMN_FIELD = 'columnId';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Whether the draft still looks exactly as these ops describe it. A
     * missing row counts as a mismatch, not as nothing to do.
     *
     * @param list<array<string, mixed>> $ops
     */
    public function matches(array $ops): bool
    {
        foreach ($ops as $op) {
            $entity = $this->resolve($op);
            if ($entity === null) {
                return false;
            }

            /** @var array<string, mixed> $set */
            $set = \is_array($op['set'] ?? null) ? $op['set'] : [];
            foreach ($set as $field => $value) {
                if (!self::same($this->read($entity, $field), $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $ops
     */
    public function apply(array $ops): void
    {
        foreach ($ops as $op) {
            $entity = $this->resolve($op);
            if ($entity === null) {
                continue;
            }

            /** @var array<string, mixed> $set */
            $set = \is_array($op['set'] ?? null) ? $op['set'] : [];
            if ($entity instanceof Block && \array_key_exists(self::COLUMN_FIELD, $set)) {
                $this->write($entity, self::COLUMN_FIELD, $set[self::COLUMN_FIELD]);
                unset($set[self::COLUMN_FIELD]);
            }

            foreach ($set as $field => $value) {
                $this->write($entity, $field, $value);
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function resolve(array $op): Block|Column|Section|null
    {
        $id = $op['id'] ?? null;
        if (!\is_int($id)) {
            return null;
        }

        return match ($op['t'] ?? null) {
            AreaStateSnapshot::T_BLOCK => $this->em->find(Block::class, $id),
            AreaStateSnapshot::T_COLUMN => $this->em->find(Column::class, $id),
            AreaStateSnapshot::T_SECTION => $this->em->find(Section::class, $id),
            default => null,
        };
    }

    private function read(Block|Column|Section $entity, string $field): mixed
    {
        return match (true) {
            $entity instanceof Block => match ($field) {
                'deleted' => $entity->isDeleted(),
                'previewPosition' => $entity->getPreviewPosition(),
                'columnId' => $entity->getColumn()?->getId(),
                'publishedColumnId' => $entity->getPublishedColumnId(),
                'data' => $entity->getDraftData(),
                default => null,
            },
            $entity instanceof Column => match ($field) {
                'deleted' => $entity->isDeleted(),
                'previewPosition' => $entity->getPreviewPosition(),
                'preset' => $entity->getPreset(),
                default => null,
            },
            default => match ($field) {
                'deleted' => $entity->isDeleted(),
                'previewPosition' => $entity->getPreviewPosition(),
                'layout' => $entity->getLayout(),
                'settings' => $entity->getDraftSettings(),
                default => null,
            },
        };
    }

    private function write(Block|Column|Section $entity, string $field, mixed $value): void
    {
        if ($entity instanceof Block) {
            $this->writeBlock($entity, $field, $value);

            return;
        }

        if ($entity instanceof Column) {
            match ($field) {
                'deleted' => $entity->setDeleted((bool) $value),
                'previewPosition' => $entity->setPreviewPosition((int) $value),
                'preset' => \is_string($value) ? $entity->setPreset($value) : $entity,
                default => null,
            };

            return;
        }

        match ($field) {
            'deleted' => $entity->setDeleted((bool) $value),
            'previewPosition' => $entity->setPreviewPosition((int) $value),
            'layout' => \is_string($value) ? $entity->setLayout($value) : $entity,
            'settings' => $entity->setDraftSettings(\is_array($value) ? $value : null),
            default => null,
        };
    }

    /**
     * `moveTo()` rather than `setColumn()`, for the reason the mover has it —
     * then the recorded published column overwrites what it just guessed.
     *
     * @see docs/internals/publishing.md#the-rule-the-whole-design-rests-on
     */
    private function writeBlock(Block $block, string $field, mixed $value): void
    {
        if ($field === self::COLUMN_FIELD) {
            $column = \is_int($value) ? $this->em->find(Column::class, $value) : null;
            if ($column instanceof Column) {
                $block->moveTo($column);
            }

            return;
        }

        match ($field) {
            'deleted' => $block->setDeleted((bool) $value),
            'previewPosition' => $block->setPreviewPosition((int) $value),
            'publishedColumnId' => $block->setPublishedColumnId(\is_int($value) ? $value : null),
            'data' => $block->setDraftData(\is_array($value) ? $value : null),
            default => null,
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b)) {
            return $a == $b;
        }

        return $a === $b;
    }
}
