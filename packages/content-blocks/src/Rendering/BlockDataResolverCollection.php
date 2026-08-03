<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;

/**
 * Runs the registered {@see BlockDataResolverInterface} services in tag
 * priority order, threading each one's output into the next — block-side
 * counterpart of {@see \ContentBlocks\Block\BlockDecoratorCollection}, but a
 * pipeline rather than an accumulator, because the payload is one value the
 * resolvers refine in turn.
 *
 * An empty chain yields an empty payload; the package always registers
 * {@see CoreBlockDataResolver}, so in practice the seed is the block's own
 * draft-or-published data.
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
