<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;

/**
 * Outcome of a paste. `droppedFields`, not `unknownFields`: a template keeps an
 * undeclared key and reports it, a clipboard entry has it thrown away.
 *
 * @see docs/internals/clipboard.md#why-the-clipboard-needs-a-replayer
 */
final class PasteResult
{
    /**
     * @param Section|Block $entity            placed, ready to persist
     * @param int           $skippedBlockCount blocks whose type is gone
     * @param list<string>  $skippedBlockTypes distinct type ids of those
     * @param list<array{
     *     blockType: string,
     *     droppedFields: list<string>,
     * }> $droppedFields
     */
    public function __construct(
        public readonly Section|Block $entity,
        public readonly int $skippedBlockCount = 0,
        public readonly array $skippedBlockTypes = [],
        public readonly array $droppedFields = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->skippedBlockCount > 0 || $this->droppedFields !== [];
    }
}
