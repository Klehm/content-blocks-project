<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * One translatable value of one block in one locale. Everything downstream
 * works on these, so the shape of `Block.data` stops mattering here.
 */
final class TranslatableField
{
    /**
     * @param string      $path        ids filled in: `items[9f2c1a].label`
     * @param string      $pattern     the shape it came from: `items[].label`
     * @param string      $label       form label, possibly a humanized fallback
     * @param string|null $labelDomain domain the label belongs to
     * @param string      $widget      text|textarea|html|url|email — also tells
     *                                 a translator whether this is markup
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
        ];
    }
}
