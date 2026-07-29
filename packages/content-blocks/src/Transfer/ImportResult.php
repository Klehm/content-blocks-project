<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

/**
 * Outcome of importing a payload into a ContentArea: what came in, and what
 * could not.
 *
 * The import is **optimistic**: everything this installation can use is brought
 * in, and the rest is reported rather than aborting the whole transfer. A
 * payload comes from another installation, so the two apps not having identical
 * blocks is the normal case — refusing would make cross-install transfer
 * useless.
 *
 * The two discrepancies are treated differently, because "compatible" is judged
 * per **block**, not per key:
 *
 *  - a block whose type is not registered here is **skipped**. It would render
 *    nothing (no view template) and offer no edit form, so importing it would
 *    hand the editor an inert placeholder. Nothing is lost: the payload file is
 *    the archive — install the block type and re-import.
 *  - a stored key no registered type declares is **kept**. The block itself is
 *    perfectly usable, the key harms nothing, and it may well be a field the
 *    host is about to add.
 */
final class ImportResult
{
    /**
     * @param int                                                       $sectionCount      imported sections
     * @param int                                                       $skippedBlockCount blocks left out because their type is unknown here
     * @param list<string>                                              $skippedBlockTypes distinct type ids of those blocks
     * @param list<array{blockType: string, unknownKeys: list<string>}> $unknownFields     kept keys no registered type declares
     */
    public function __construct(
        public readonly int $sectionCount,
        public readonly int $skippedBlockCount = 0,
        public readonly array $skippedBlockTypes = [],
        public readonly array $unknownFields = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->skippedBlockCount > 0 || $this->unknownFields !== [];
    }
}
