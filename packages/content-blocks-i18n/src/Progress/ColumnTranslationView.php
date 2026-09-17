<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Progress;

use ContentBlocks\I18n\Field\TranslatableField;

/**
 * One column's tab or panel title in one locale, shaped like a block view so
 * the workbench, the progress totals and the machine run treat both alike.
 *
 * @see docs/internals/i18n.md#tab-titles-are-translated-beside-the-column
 */
final class ColumnTranslationView
{
    public const KIND = 'column';

    /**
     * @param list<TranslatableField> $fields
     */
    public function __construct(
        public readonly int $columnId,
        public readonly string $label,
        public readonly int $sectionId,
        public readonly int $sectionNumber,
        public readonly int $columnNumber,
        public readonly array $fields,
        public readonly TranslationProgress $progress,
        public readonly string $group = '',
    ) {
    }

    /** Distinct from a block id, which lives in another table's sequence. */
    public static function keyOf(int $columnId): string
    {
        return self::KIND . '-' . $columnId;
    }

    public function key(): string
    {
        return self::keyOf($this->columnId);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'key' => $this->key(),
            'columnId' => $this->columnId,
            'blockLabel' => $this->label,
            'groupLabel' => $this->group,
            'sectionId' => $this->sectionId,
            'sectionNumber' => $this->sectionNumber,
            'columnNumber' => $this->columnNumber,
            'fields' => array_map(static fn (TranslatableField $f): array => $f->toArray(), $this->fields),
            'progress' => $this->progress->toArray(),
        ];
    }
}
