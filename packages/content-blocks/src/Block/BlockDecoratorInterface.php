<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\Entity\Block;

/**
 * Extension point for the block render pipeline: autoconfigured, called per
 * rendered block, merged in service order.
 *
 * @see docs/internals/forms.md#block-decorators
 */
interface BlockDecoratorInterface
{
    /** @param array<string, mixed> $data effective data, draft or published */
    public function decorate(array $data, Block $block): BlockDecoration;
}
