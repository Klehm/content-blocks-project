<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

/**
 * The block as its view template sees it (`block`): read-only, and never the
 * entity, whose draft a public render must not reach.
 *
 * @see docs/internals/rendering.md#what-a-block-view-receives
 */
final class BlockView
{
    public function __construct(
        /** Null when a view is rendered on its own, in a test for instance. */
        public readonly ?int $id,
        /** The block type, e.g. `title`: one template can serve several. */
        public readonly string $type,
    ) {
    }
}
