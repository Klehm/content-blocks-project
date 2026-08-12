<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * Turns "a block type, its source data, and what is stored for a locale" into
 * the flat list of translatable fields with their status.
 *
 * This is the join point of the three inputs the design keeps separate:
 *
 *  - **which** fields may be translated — the core's `cb_translatable` tags,
 *    read through {@see TranslatableFieldsInterface}, the frozen convention;
 *  - **how** they look — {@see FieldMetadataReader}, labels and widgets;
 *  - **what** is stored — the locale's value and digest maps.
 *
 * Pure: no entity, no database, no request. The store hands it maps and it hands
 * back value objects, which is what makes the status rules testable in isolation
 * — and they are the rules most likely to be argued about.
 */
final class TranslatableFieldCatalog
{
    public function __construct(
        private readonly TranslatableFieldsInterface $translatableFields,
        private readonly FieldMetadataReader $metadata,
    ) {
    }

    /**
     * @param array<string, mixed>  $sourceData the block's own data — the source locale
     * @param array<string, mixed>  $values     stored translations, path => value
     * @param array<string, string> $digests    source fingerprints, path => digest
     *
     * @return list<TranslatableField>
     */
    public function build(string $blockType, array $sourceData, array $values = [], array $digests = []): array
    {
        $patterns = $this->translatableFields->forBlockType($blockType, $sourceData);

        if ($patterns === []) {
            return [];
        }

        $metadata = $this->metadata->forBlockType($blockType, $sourceData);
        $fields = [];

        foreach ($patterns as $pattern) {
            $meta = $metadata[$pattern] ?? null;

            foreach (FieldPath::expand($pattern, $sourceData) as $path) {
                $source = FieldPath::read($sourceData, $path);

                // Only text is offered for translation, and only text that
                // exists. A blank optional caption is not untranslated work, and
                // counting it as such would park every page short of 100% for
                // reasons no editor can act on. A non-string value under a
                // translatable tag is a host mis-tagging something structural;
                // skipping is the conservative reading.
                if (!\is_string($source) || trim($source) === '') {
                    continue;
                }

                $fields[] = new TranslatableField(
                    path: $path,
                    pattern: $pattern,
                    label: $meta['label'] ?? $this->humanize($pattern),
                    labelDomain: $meta['labelDomain'] ?? null,
                    widget: $meta['widget'] ?? 'text',
                    source: $source,
                    value: $this->valueAt($values, $path),
                    status: $this->statusAt($values, $digests, $path, $source),
                    entryIndex: FieldPath::entryIndex($sourceData, $path),
                );
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function valueAt(array $values, string $path): ?string
    {
        $value = $values[$path] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $digests
     */
    private function statusAt(array $values, array $digests, string $path, string $source): FieldStatus
    {
        // array_key_exists, not isset, and not a truthiness check: `''` is a
        // deliberate translation ("this label is empty in German") and must not
        // read as missing, which would make the render fall back to the English.
        if (!\array_key_exists($path, $values) || !\is_string($values[$path])) {
            return FieldStatus::MISSING;
        }

        return SourceDigest::matches($source, $digests[$path] ?? null)
            ? FieldStatus::TRANSLATED
            : FieldStatus::OUTDATED;
    }

    private function humanize(string $pattern): string
    {
        $segments = FieldPath::segments($pattern);
        $last = $segments === [] ? $pattern : end($segments)['name'];

        return ucfirst(trim(strtolower((string) preg_replace('/(?<!^)[A-Z]|_/', ' $0', $last))));
    }
}
