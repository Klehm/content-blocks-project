<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\ContentArea;

/**
 * Hydrates a payload produced by {@see ContentAreaExporterInterface} into draft
 * sections on a target ContentArea.
 *
 * Override seam: the bundle aliases this to the shipped {@see ContentAreaImporter}.
 */
interface ContentAreaImporterInterface
{
    /**
     * **Replace semantics**, mirroring the "Insert content" flow: the target's
     * existing sections are soft-deleted (the actual removal happens at the
     * next publish) and the imported ones are added as never-published drafts.
     *
     * Embedded asset binaries are re-stored and the `asset://{hash}` tokens
     * rewritten in place to their new public paths; an unknown hash is left
     * untouched so the problem surfaces in the UI instead of vanishing.
     *
     * Does **not** flush — this builds, it does not commit (see
     * {@see ContentAreaPublisherInterface} for the rule).
     *
     * @param array<string, mixed> $payload
     *
     * @return int number of imported sections
     *
     * @throws \InvalidArgumentException when the payload's format is unsupported or its shape invalid
     */
    public function import(ContentArea $target, array $payload): int;
}
