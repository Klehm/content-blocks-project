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
     * {@see \ContentBlocks\Publishing\ContentAreaPublisherInterface} for the rule).
     *
     * Only the **envelope** is validated, and a bad one throws: the payload is a
     * file that travelled, so an unreadable structure must not be replayed.
     * Everything about the *content* is reported rather than refused — an
     * unregistered block type or a stored key no type can hold comes back in the
     * {@see ImportResult}, never dropped and never blocking. See that class for
     * why this differs from the section-template flow.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException when the payload's format is unsupported or its shape invalid
     */
    public function import(ContentArea $target, array $payload): ImportResult;
}
