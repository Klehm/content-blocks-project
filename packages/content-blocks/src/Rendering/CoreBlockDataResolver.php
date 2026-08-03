<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;

/**
 * Seeds the render payload from the entity's own slots — the rule that used to
 * live inline in {@see BlockRenderer}:
 *
 *  - PREVIEW shows the in-flight edit, falling back to the published value
 *    (a block edited since the last publish, or never published at all);
 *  - PUBLIC shows only what was published; a block with no published payload
 *    renders empty (it is filtered out upstream anyway).
 *
 * Registered at priority 256 so it runs before any host resolver: everything
 * else in the chain transforms a payload rather than producing one. A host that
 * wants a different seeding rule registers a resolver at a higher priority and
 * ignores the incoming `$data`.
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
