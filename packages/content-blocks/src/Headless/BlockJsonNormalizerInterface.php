<?php

declare(strict_types=1);

namespace ContentBlocks\Headless;

use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\RenderContext;

/**
 * What a block type's data becomes for a client that has no Twig view: URLs
 * resolved, HTML sanitized. Autoconfigured; each one checks the type itself.
 *
 * @see docs/guide/headless.md#what-a-block-sends
 */
interface BlockJsonNormalizerInterface
{
    /**
     * @param array<string, mixed> $data what the data resolvers produced, or
     *      the previous normalizer
     *
     * @return array<string, mixed>
     */
    public function normalize(BlockView $block, array $data, RenderContext $context): array;
}
