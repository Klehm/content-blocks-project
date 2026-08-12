<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Content;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Walks an area's live blocks in the order an editor reads them.
 *
 * Every consumer in this package — progress, the workbench, the bulk
 * translator — needs the same walk, and two of them producing different orders
 * would be visible as a workbench whose rows do not line up with its own
 * progress bar. So the walk lives once, here.
 *
 * Two rules it encodes:
 *
 *  - **soft-deleted entities are skipped.** A block in the trash is not
 *    untranslated work; counting it would make a page that reads as complete
 *    report 94%.
 *  - **ordering is by `previewPosition`, not by the collections' own
 *    `OrderBy(position)`.** `position` is the *published* order; the builder,
 *    and therefore anyone translating, sees the draft order. Sorting has to be
 *    explicit because the Doctrine mapping cannot do it.
 */
final class AreaWalker
{
    /**
     * @return list<BlockRef>
     */
    public static function blocks(ContentArea $area): array
    {
        $out = [];

        foreach (self::sections($area) as $sectionIndex => $section) {
            foreach (self::columns($section) as $columnIndex => $column) {
                foreach (self::columnBlocks($column) as $blockIndex => $block) {
                    $out[] = new BlockRef($block, $column, $section, $sectionIndex + 1, $columnIndex + 1, $blockIndex + 1);
                }
            }
        }

        return $out;
    }

    /** @return list<Section> */
    public static function sections(ContentArea $area): array
    {
        return self::ordered(
            array_filter($area->getSections()->toArray(), static fn (Section $s) => !$s->isDeleted()),
            static fn (Section $s) => $s->getPreviewPosition(),
        );
    }

    /** @return list<Column> */
    public static function columns(Section $section): array
    {
        return self::ordered(
            array_filter($section->getColumns()->toArray(), static fn (Column $c) => !$c->isDeleted()),
            static fn (Column $c) => $c->getPreviewPosition(),
        );
    }

    /** @return list<Block> */
    public static function columnBlocks(Column $column): array
    {
        return self::ordered(
            array_filter($column->getBlocks()->toArray(), static fn (Block $b) => !$b->isDeleted()),
            static fn (Block $b) => $b->getPreviewPosition(),
        );
    }

    /**
     * @template T of object
     *
     * @param array<array-key, T>  $items
     * @param callable(T): int     $position
     *
     * @return list<T>
     */
    private static function ordered(array $items, callable $position): array
    {
        $items = array_values($items);
        usort($items, static fn (object $a, object $b): int => $position($a) <=> $position($b));

        return $items;
    }
}
