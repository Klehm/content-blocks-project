<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * The shape of a column's settings, kept in one place because they arrive
 * from untrusted payloads: a clipboard, an import file, a saved template.
 *
 * @see docs/internals/rendering.md#columns-and-tabs
 */
final class ColumnSettings
{
    public const LABEL = 'label';
    public const LABEL_MAX_LENGTH = 100;

    /**
     * Known keys only, well-formed values only. Empty leaves are dropped, so
     * `[]` means "nothing set".
     *
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        $label = $raw[self::LABEL] ?? null;
        if (\is_string($label)) {
            $label = trim(mb_substr($label, 0, self::LABEL_MAX_LENGTH));
            if ($label !== '') {
                $out[self::LABEL] = $label;
            }
        }

        return $out;
    }

    /**
     * The column's label, or null when it has none.
     *
     * @param array<string, mixed> $settings
     */
    public static function label(array $settings): ?string
    {
        $label = $settings[self::LABEL] ?? null;

        return \is_string($label) && trim($label) !== '' ? $label : null;
    }
}
