<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\BlockType;

use ContentBlocks\BlockType\AsContentBlock;

/** Re-applies the attribute without a category, as a kit subclass does. */
#[AsContentBlock(priority: 5)]
class MediaSubclassFixtureBlock extends MediaFixtureParentBlock
{
    public function getType(): string
    {
        return 'fixture_media_subclass';
    }
}
