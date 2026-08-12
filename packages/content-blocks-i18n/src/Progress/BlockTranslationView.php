<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Progress;

use ContentBlocks\I18n\Field\TranslatableField;

/**
 * One block's translatable fields in one locale, with the labels a list needs
 * to place them — the row group the workbench renders, and the unit the bulk
 * translator batches.
 *
 * Deliberately flat and serializable: the whole point of the catalog layer is
 * that nothing past it has to know the shape of `Block.data`, so this carries
 * strings and value objects rather than entities.
 */
final class BlockTranslationView
{
    /**
     * @param list<TranslatableField> $fields
     */
    public function __construct(
        public readonly int $blockId,
        public readonly string $blockType,
        public readonly string $blockLabel,
        public readonly int $sectionId,
        public readonly int $sectionNumber,
        public readonly int $blockNumber,
        public readonly array $fields,
        public readonly TranslationProgress $progress,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'blockId' => $this->blockId,
            'blockType' => $this->blockType,
            'blockLabel' => $this->blockLabel,
            'sectionId' => $this->sectionId,
            'sectionNumber' => $this->sectionNumber,
            'blockNumber' => $this->blockNumber,
            'fields' => array_map(static fn (TranslatableField $f): array => $f->toArray(), $this->fields),
            'progress' => $this->progress->toArray(),
        ];
    }
}
