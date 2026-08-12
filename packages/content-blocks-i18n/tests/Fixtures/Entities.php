<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Fixtures;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Builds real entity graphs with ids assigned, so the store and the walker can
 * be exercised without a database. Ids are normally Doctrine's to hand out;
 * reflection is the same shortcut the core's renderer tests take.
 */
final class Entities
{
    public static function block(int $id, string $type = 'fixture', array $draft = [], ?array $published = null, int $position = 0): Block
    {
        $block = new Block();
        $block->setType($type);
        $block->setDraftData($draft === [] ? null : $draft);
        $block->setPublishedData($published);
        $block->setPreviewPosition($position);
        self::id($block, $id);

        return $block;
    }

    /**
     * A one-section, one-column area holding the given blocks in order.
     */
    public static function area(int $id, Block ...$blocks): ContentArea
    {
        $area = new ContentArea();
        self::id($area, $id);

        $section = new Section();
        self::id($section, $id * 100);
        $section->setPreviewPosition(0);

        $column = new Column();
        self::id($column, $id * 1000);
        $column->setPreviewPosition(0);

        foreach ($blocks as $block) {
            $column->addBlock($block);
        }

        $section->addColumn($column);
        $area->addSection($section);

        return $area;
    }

    public static function id(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setValue($entity, $id);
    }
}
