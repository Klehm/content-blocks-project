<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\Entity\Block;

/**
 * Iterates registered block decorators in service-order, accumulating
 * their output into a single {@see BlockDecoration}.
 */
final class BlockDecoratorCollection
{
    /**
     * @param iterable<BlockDecoratorInterface> $decorators
     */
    public function __construct(
        private readonly iterable $decorators,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function decorate(array $data, Block $block): BlockDecoration
    {
        $result = new BlockDecoration();
        foreach ($this->decorators as $decorator) {
            $result = $result->merge($decorator->decorate($data, $block));
        }

        return $result;
    }
}
