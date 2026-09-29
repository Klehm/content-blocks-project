<?php

declare(strict_types=1);

namespace ContentBlocks\Content;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * The draft order of siblings: live ones, by `previewPosition`. The mapped
 * collections sort by the *published* position, so they cannot be used.
 *
 * @internal
 *
 * @see docs/internals/content-manipulation.md#draft-order
 */
final class DraftOrder
{
    /** @return list<Section> */
    public static function sections(ContentArea $area): array
    {
        return self::live($area->getSections());
    }

    /** @return list<Column> */
    public static function columns(Section $section): array
    {
        return self::live($section->getColumns());
    }

    /** @return list<Block> */
    public static function blocks(Column $column): array
    {
        return self::live($column->getBlocks());
    }

    /**
     * One past the highest position, deleted rows included, so an append
     * never lands on a row Discard may bring back.
     *
     * @param iterable<Section|Column|Block> $siblings
     */
    public static function next(iterable $siblings): int
    {
        $max = -1;
        foreach ($siblings as $sibling) {
            $max = max($max, $sibling->getPreviewPosition());
        }

        return $max + 1;
    }

    /**
     * @template T of Section|Column|Block
     *
     * @param list<T> $siblings
     *
     * @return list<T>
     */
    public static function without(array $siblings, Section|Column|Block $node): array
    {
        return array_values(array_filter($siblings, static fn ($s): bool => $s !== $node));
    }

    /**
     * Puts `$node` at `$position` (clamped) and numbers the list 0..n.
     *
     * @template T of Section|Column|Block
     *
     * @param list<T> $siblings
     * @param T       $node
     */
    public static function insert(array $siblings, Section|Column|Block $node, int $position): void
    {
        $position = max(0, min($position, \count($siblings)));
        array_splice($siblings, $position, 0, [$node]);
        self::reindex($siblings);
    }

    /**
     * The position right after `$anchor`, or the end when there is no anchor
     * or it is not among the siblings.
     *
     * @param list<Section|Column|Block> $siblings
     */
    public static function after(array $siblings, Section|Column|Block|null $anchor): int
    {
        $index = $anchor === null ? false : array_search($anchor, $siblings, true);

        return $index === false ? \count($siblings) : $index + 1;
    }

    /** @param list<Section|Column|Block> $siblings */
    public static function reindex(array $siblings): void
    {
        foreach ($siblings as $i => $sibling) {
            $sibling->setPreviewPosition($i);
        }
    }

    /**
     * @template T of Section|Column|Block
     *
     * @param iterable<T> $nodes
     *
     * @return list<T>
     */
    private static function live(iterable $nodes): array
    {
        $live = [];
        foreach ($nodes as $node) {
            if (!$node->isDeleted()) {
                $live[] = $node;
            }
        }
        usort($live, static fn ($a, $b): int => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $live;
    }
}
