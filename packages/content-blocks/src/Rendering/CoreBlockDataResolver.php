<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;

/**
 * Seeds the render payload from the entity slots, at priority 256 so it runs
 * before any host resolver.
 *
 * @see docs/internals/rendering.md#resolving-what-a-block-renders
 */
final class CoreBlockDataResolver implements BlockDataResolverInterface
{
    public function resolve(Block $block, RenderContext $context, array $data): array
    {
        return $context->mode === RenderMode::PREVIEW
            ? ($block->getDraftData() ?? $block->getPublishedData() ?? [])
            : ($block->getPublishedData() ?? []);
    }
}
