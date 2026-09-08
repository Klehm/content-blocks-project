<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Progress;

use ContentBlocks\I18n\Field\TranslatableField;

/**
 * One block's translatable fields in one locale. Flat and serializable, so
 * nothing past the catalog layer knows the shape of `Block.data`.
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
