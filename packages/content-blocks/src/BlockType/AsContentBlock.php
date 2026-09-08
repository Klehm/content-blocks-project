<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Auto-registers a block in the BlockTypeRegistry. Its `priority` decides
 * where the block sits in the picker grid.
 *
 * @see docs/internals/blocks.md#registration-order-is-the-picker-order
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsContentBlock
{
    /**
     * @param int $priority Higher priority blocks appear first in the block
     *                      picker grid. Blocks sharing a priority keep their
     *                      service-discovery order. Defaults to 0.
     */
    public function __construct(public readonly int $priority = 0)
    {
    }
}
