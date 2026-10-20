<?php

declare(strict_types=1);

namespace ContentBlocks\Headless;

use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\RenderContext;

/**
 * Threads each {@see BlockJsonNormalizerInterface} output into the next.
 *
 * @internal
 */
final class BlockJsonNormalizerCollection
{
    /**
     * @param iterable<BlockJsonNormalizerInterface> $normalizers
     */
    public function __construct(
        private readonly iterable $normalizers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function normalize(BlockView $block, array $data, RenderContext $context): array
    {
        foreach ($this->normalizers as $normalizer) {
            $data = $normalizer->normalize($block, $data, $context);
        }

        return $data;
    }
}
