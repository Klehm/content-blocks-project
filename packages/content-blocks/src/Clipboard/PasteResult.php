<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;

/**
 * Outcome of a paste: what landed, and what did not come with it.
 *
 * The vocabulary differs from {@see \ContentBlocks\SectionTemplate\InstantiationResult}
 * on purpose. A template *keeps* a stored key no type declares and reports it;
 * a clipboard entry is user-writable, so the same key is **dropped** — see
 * {@see BlockDataReplayer}. Hence `droppedFields` where the template flow says
 * `unknownFields`: one warns about what it kept, the other about what it threw
 * away.
 */
final class PasteResult
{
    /**
     * @param Section|Block                                              $entity            the pasted entity, already placed and ready to persist
     * @param int                                                        $skippedBlockCount blocks left out because their type is no longer registered
     * @param list<string>                                               $skippedBlockTypes distinct type ids of those blocks
     * @param list<array{blockType: string, droppedFields: list<string>}> $droppedFields     fields reset to their type's default
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
