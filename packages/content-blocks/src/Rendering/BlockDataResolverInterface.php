<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;

/**
 * Extension point for **what a block renders**, as opposed to
 * {@see \ContentBlocks\Block\BlockDecoratorInterface}, which decides how its
 * wrapper looks. A decorator contributes classes, inline styles and attributes
 * and by contract cannot touch `data`; this is the seam that can.
 *
 * Tag with `content_blocks.block_data_resolver` (or just implement the
 * interface — it is autoconfigured) and the pipeline calls you for every block
 * being rendered. Resolvers run in tag priority order, each receiving what the
 * previous one produced, so the shipped {@see CoreBlockDataResolver} seeds the
 * payload (draft-or-published, per mode) at priority 256 and everything else
 * transforms it.
 *
 * The motivating case is content translation: a satellite package registers a
 * resolver that merges `$context->locale`'s field values over the source-locale
 * payload. Others fit the same shape — resolving placeholder tokens, injecting
 * computed values, A/B variants.
 *
 * Contract:
 *  - **return the payload**, do not mutate in place; returning `$data`
 *    unchanged is a valid no-op;
 *  - stay side-effect free — this runs once per block on every public page
 *    render, and may run inside a warm cache;
 *  - `$context->mode` is always resolved here (never null), so branching on
 *    PREVIEW vs PUBLIC is safe.
 */
interface BlockDataResolverInterface
{
    /**
     * @param array<string, mixed> $data payload accumulated so far (empty for the first resolver)
     *
     * @return array<string, mixed>
     */
    public function resolve(Block $block, RenderContext $context, array $data): array;
}
