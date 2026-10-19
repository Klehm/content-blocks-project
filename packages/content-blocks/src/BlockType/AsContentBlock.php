<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Auto-registers a block in the BlockTypeRegistry. `category` picks its group
 * in the picker, `priority` its place within the group.
 *
 * @see docs/internals/blocks.md#registration-order-is-the-picker-order
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsContentBlock
{
    /**
     * @param int         $priority Higher priority blocks appear first in
     *                              their picker group. Blocks sharing a
     *                              priority keep their discovery order.
     * @param string|null $category A {@see BlockCategoryRegistry} name: text,
     *                              media, layout, actions, advanced, or one
     *                              declared under content_blocks.picker.
     *                              Null lands in "other".
     */
    public function __construct(
        public readonly int $priority = 0,
        public readonly ?string $category = null,
    ) {
    }
}
