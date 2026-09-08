<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;

/**
 * Extension point for *what* a block renders. Autoconfigured; resolvers run in
 * tag priority order, each refining what the previous one produced.
 *
 * @see docs/internals/rendering.md#resolving-what-a-block-renders
 */
interface BlockDataResolverInterface
{
    /**
     * @param array<string, mixed> $data so far (empty for the first resolver)
     *
     * @return array<string, mixed>
     */
    public function resolve(Block $block, RenderContext $context, array $data): array;
}
