<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

/**
 * What came in, and what could not. The import is optimistic: an unknown block
 * type is skipped, an undeclared key is kept, and both are reported.
 *
 * @see docs/internals/section-templates.md#skipped-blocks-versus-kept-keys
 */
final class ImportResult
{
    /**
     * @param int          $sectionCount      imported sections
     * @param int          $skippedBlockCount blocks whose type is unknown here
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
