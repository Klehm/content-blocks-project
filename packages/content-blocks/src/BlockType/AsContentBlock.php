<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Attribute to auto-register a block in the BlockTypeRegistry via the CompilerPass.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsContentBlock
{
    public function __construct()
    {
    }
}
