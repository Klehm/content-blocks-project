<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * Joins the three inputs the design keeps separate — which fields, how they
 * look, what is stored — into a flat list. Pure: no entity, no database.
 *
 * @see docs/internals/i18n.md#three-states-not-two
 */
final class TranslatableFieldCatalog
{
    public function __construct(
        private readonly TranslatableFieldsInterface $translatableFields,
        private readonly FieldMetadataReader $metadata,
    ) {
    }

    /**
     * @param array<string, mixed>  $sourceData the block's own, source locale
     * @param array<string, mixed>  $values     stored, path => value
     * @param array<string, string> $digests    fingerprints, path => digest
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

                // A blank optional caption is not untranslated work, and
                // counting it would park every page short of 100%.
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
        // array_key_exists, not isset: `''` is a deliberate translation and
        // must not read as missing, which would fall back to the source.
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
