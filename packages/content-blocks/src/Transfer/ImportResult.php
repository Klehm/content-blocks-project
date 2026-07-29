<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

/**
 * Outcome of importing a payload into a ContentArea: how much came in, plus the
 * non-blocking discrepancies worth showing the editor.
 *
 * Neither warning aborts the import. That is a deliberate difference from the
 * section-template flow, which *refuses* an unknown block type: a template comes
 * from the same app, so a missing type means it was deleted — an anomaly. An
 * import comes from another installation, where the two apps not having the same
 * blocks is the normal case. Refusing there would make cross-install transfer
 * useless; saying nothing (what the importer did before) silently produced
 * blocks nothing can render.
 */
final class ImportResult
{
    /**
     * @param int                                                       $sectionCount  imported sections
     * @param list<string>                                              $missingBlockTypes  referenced type ids absent from this app's registry
     * @param list<array{blockType: string, unknownKeys: list<string>}> $unknownFields stored keys no registered type can hold
     */
    public function __construct(
        public readonly int $sectionCount,
        public readonly array $missingBlockTypes = [],
        public readonly array $unknownFields = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->missingBlockTypes !== [] || $this->unknownFields !== [];
    }
}
