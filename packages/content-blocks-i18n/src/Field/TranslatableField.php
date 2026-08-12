<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * One translatable value of one block, in one target locale — the unit the
 * workbench renders as a row, the progress calculator counts, and the machine
 * translator sends off.
 *
 * Everything downstream works on these rather than on raw block data, so the
 * shape of `Block.data` stops mattering past this point.
 */
final class TranslatableField
{
    /**
     * @param string      $path        concrete address, ids filled in: `items[9f2c1a].label`
     * @param string      $pattern     the shape it came from: `items[].label`
     * @param string      $label       the field's form label — a translation key for kit blocks,
     *                                 hence $labelDomain; may be a humanized fallback
     * @param string|null $labelDomain translation domain the label belongs to
     * @param string      $widget      `text` | `textarea` | `html` | `url` | `email` — what the
     *                                 workbench should render, and the hint a machine translator
     *                                 needs to know whether it is handling markup
     * @param string      $source      the source-locale text (never blank — blank fields are not
     *                                 collected, there is nothing to translate)
     * @param string|null $value       the stored translation, or null when missing
     * @param int|null    $entryIndex  1-based position of the collection entry this field belongs
     *                                 to, for labelling ("Card 2"); null outside collections
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
