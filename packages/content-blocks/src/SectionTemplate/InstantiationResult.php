<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Entity\Section;

/**
 * The detached draft Section, plus what could not be brought along. Same
 * optimistic rule as {@see \ContentBlocks\Transfer\ImportResult}.
 *
 * @see docs/internals/section-templates.md#skipped-blocks-versus-kept-keys
 */
final class InstantiationResult
{
    /**
     * @param int          $skippedBlockCount blocks whose type is unregistered
     * @param list<string> $skippedBlockTypes distinct type ids of those
     * @param list<array{
     *     blockType: string,
     *     unknownKeys: list<string>,
     * }> $unknownFields kept keys no registered type declares
     *
     * @internal hosts receive these objects, they do not build them; see
     *           FREEZE-AUDIT.md
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
