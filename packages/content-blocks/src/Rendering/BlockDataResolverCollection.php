<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;

/**
 * Threads each {@see BlockDataResolverInterface} output into the next. An empty
 * chain yields an empty payload; {@see CoreBlockDataResolver} is always there.
 *
 * @see docs/internals/rendering.md#resolving-what-a-block-renders
 */
final class BlockDataResolverCollection
{
    /**
     * @param iterable<BlockDataResolverInterface> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
    ) {
    }

    /** @return array<string, mixed> */
    public function resolve(Block $block, RenderContext $context): array
    {
        $data = [];
        foreach ($this->resolvers as $resolver) {
            $data = $resolver->resolve($block, $context, $data);
        }

        return $data;
    }
}
