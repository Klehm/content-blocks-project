<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Entity\Section;

/**
 * Outcome of instantiating a section template: the detached draft Section ready
 * to be attached to a ContentArea, plus what could not be brought along.
 *
 * Same optimistic rule as {@see \ContentBlocks\Transfer\ImportResult}, and the
 * same vocabulary: a block whose type is no longer registered is **skipped**
 * (it would be inert — no view template, no edit form — and the stored payload
 * remains the archive), while a stored key nothing declares is **kept** and
 * merely reported.
 *
 * The one hard stop left is a template where *every* block would be skipped:
 * there is nothing left to insert, so {@see IncompatibleTemplateException} is
 * thrown rather than dropping an empty section into the area.
 */
final class InstantiationResult
{
    /**
     * @param int                                                       $skippedBlockCount blocks left out because their type is no longer registered
     * @param list<string>                                              $skippedBlockTypes distinct type ids of those blocks
     * @param list<array{blockType: string, unknownKeys: list<string>}> $unknownFields     kept keys no registered type declares
     */
    public function __construct(
        public readonly Section $section,
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
