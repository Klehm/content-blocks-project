<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Progress;

use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\TranslatableField;

/**
 * How far one locale has got. `percent` counts only fields both present and
 * current, so a rewritten source drops the page back below 100%.
 *
 * @see docs/internals/i18n.md#three-states-not-two
 */
final class TranslationProgress
{
    public function __construct(
        public readonly string $locale,
        public readonly int $translated = 0,
        public readonly int $outdated = 0,
        public readonly int $missing = 0,
    ) {
    }

    /**
     * @param iterable<TranslatableField> $fields
     */
    public static function of(string $locale, iterable $fields): self
    {
        $translated = 0;
        $outdated = 0;
        $missing = 0;

        foreach ($fields as $field) {
            match ($field->status) {
                FieldStatus::TRANSLATED => ++$translated,
                FieldStatus::OUTDATED => ++$outdated,
                FieldStatus::MISSING => ++$missing,
            };
        }

        return new self($locale, $translated, $outdated, $missing);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->locale,
            $this->translated + $other->translated,
            $this->outdated + $other->outdated,
            $this->missing + $other->missing,
        );
    }

    public function getTotal(): int
    {
        return $this->translated + $this->outdated + $this->missing;
    }

    /**
     * 0–100. A scope with nothing to translate is 100 rather than 0: an area of
     * images and dividers is not "0% translated", it is done.
     */
    public function getPercent(): int
    {
        $total = $this->getTotal();

        return $total === 0 ? 100 : (int) round($this->translated / $total * 100);
    }

    public function isComplete(): bool
    {
        return $this->outdated === 0 && $this->missing === 0;
    }

    public function needsAttention(): bool
    {
        return !$this->isComplete();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'total' => $this->getTotal(),
            'translated' => $this->translated,
            'outdated' => $this->outdated,
            'missing' => $this->missing,
            'percent' => $this->getPercent(),
            'complete' => $this->isComplete(),
        ];
    }
}
