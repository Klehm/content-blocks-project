<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * One translatable value of one block in one locale. Everything downstream
 * works on these, so the shape of `Block.data` stops mattering here.
 */
final class TranslatableField
{
    /** File fields: a path, never sent to an engine, optional per locale. */
    public const MEDIA_WIDGETS = ['image', 'video'];

    /**
     * @param string      $path        ids filled in: `items[9f2c1a].label`
     * @param string      $pattern     the shape it came from: `items[].label`
     * @param string      $label       form label, possibly a humanized fallback
     * @param string|null $labelDomain domain the label belongs to
     * @param string      $widget      text|textarea|html|url|email, or
     *                                 image|video for a file path
     * @param string      $source      never blank; blank fields are not
     *                                 collected
     * @param string|null $value       stored translation, null when missing
     * @param int|null    $entryIndex  1-based, for labelling ("Card 2")
     */
    public function __construct(
        public readonly string $path,
        public readonly string $pattern,
        public readonly string $label,
        public readonly ?string $labelDomain,
        public readonly string $widget,
        public readonly string $source,
        public readonly ?string $value,
        public readonly FieldStatus $status,
        public readonly ?int $entryIndex = null,
    ) {
    }

    public function isMedia(): bool
    {
        return \in_array($this->widget, self::MEDIA_WIDGETS, true);
    }

    /**
     * A file left as the source's is shared, not unfinished work, so it stays
     * out of progress. A localized one that went stale still counts.
     */
    public function countsTowardProgress(): bool
    {
        return !$this->isMedia() || $this->status !== FieldStatus::MISSING;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'pattern' => $this->pattern,
            'label' => $this->label,
            'labelDomain' => $this->labelDomain,
            'widget' => $this->widget,
            'source' => $this->source,
            'value' => $this->value,
            'status' => $this->status->value,
            'entryIndex' => $this->entryIndex,
            'media' => $this->isMedia(),
        ];
    }
}
