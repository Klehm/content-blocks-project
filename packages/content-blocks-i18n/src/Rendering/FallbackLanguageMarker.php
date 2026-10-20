<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\I18n\Field\FieldMetadataReader;
use ContentBlocks\I18n\Field\FieldPath;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * Records, beside each text field, the language it is shown in when that is
 * not the page's: `_lang` per object, `_block_lang` when it is the whole block.
 *
 * @see docs/internals/i18n.md#the-language-of-a-fallback
 */
final class FallbackLanguageMarker
{
    public const FIELD_KEY = '_lang';
    public const BLOCK_KEY = '_block_lang';

    /** Values that are not prose: a screen reader has nothing to voice. */
    private const NOT_TEXT = ['url', 'email', 'image', 'video'];

    public function __construct(
        private readonly TranslatableFieldsInterface $translatableFields,
        private readonly FieldMetadataReader $metadata,
        private readonly TranslationLocales $locales,
    ) {
    }

    /**
     * @param array<string, mixed>  $source  the block's data before translation
     * @param array<string, mixed>  $data    the data about to render
     * @param array<string, string> $origins path => locale its value came from
     *
     * @return array<string, mixed>
     */
    public function mark(string $blockType, array $source, array $data, array $origins, string $locale): array
    {
        $metadata = $this->metadata->forBlockType($blockType, $source);
        $marks = [];
        $texts = 0;

        foreach ($this->translatableFields->forBlockType($blockType, $source) as $pattern) {
            if (\in_array($metadata[$pattern]['widget'] ?? 'text', self::NOT_TEXT, true)) {
                continue;
            }

            foreach (FieldPath::expand($pattern, $source) as $path) {
                $value = FieldPath::read($source, $path);
                if (!\is_string($value) || trim($value) === '') {
                    continue;
                }

                ++$texts;
                $from = $origins[$path] ?? $this->locales->getSourceLocale();
                if ($from !== $locale) {
                    $marks[$path] = self::tag($from);
                }
            }
        }

        if ($marks === []) {
            return $data;
        }

        // One language for the whole block: the wrapper says it once.
        if (\count($marks) === $texts && \count(array_unique($marks)) === 1) {
            $data[self::BLOCK_KEY] = reset($marks);

            return $data;
        }

        foreach ($marks as $path => $tag) {
            $data = self::withMark($data, $path, $tag);
        }

        return $data;
    }

    /** `fr_CA` is a locale id; `lang` wants the BCP 47 tag, `fr-CA`. */
    public static function tag(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    /** Whatever reaches an attribute: a BCP 47 shape, nothing else. */
    public static function isValidTag(string $tag): bool
    {
        return preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $tag) === 1;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function withMark(array $data, string $path, string $tag): array
    {
        $segments = FieldPath::segments($path);
        $leaf = array_pop($segments);

        // A scalar collection entry has no object to carry the mark.
        if ($leaf === null || $leaf['id'] !== null) {
            return $data;
        }

        return self::markInto($data, $segments, $leaf['name'], $tag) ?? $data;
    }

    /**
     * Walks to the object holding the field, as FieldPath does, and only
     * through structure that exists.
     *
     * @param array<string, mixed>                          $node
     * @param list<array{name: string, id: string|null}> $segments
     *
     * @return array<string, mixed>|null
     */
    private static function markInto(array $node, array $segments, string $leaf, string $tag): ?array
    {
        if ($segments === []) {
            $marks = \is_array($node[self::FIELD_KEY] ?? null) ? $node[self::FIELD_KEY] : [];
            $node[self::FIELD_KEY] = [$leaf => $tag] + $marks;

            return $node;
        }

        $segment = array_shift($segments);
        $child = $node[$segment['name']] ?? null;
        if (!\is_array($child)) {
            return null;
        }

        if ($segment['id'] === null) {
            $marked = self::markInto($child, $segments, $leaf, $tag);
        } else {
            $marked = null;
            foreach ($child as $key => $entry) {
                if (\is_array($entry) && ($entry[CollectionItemIds::KEY] ?? null) === $segment['id']) {
                    $entry = self::markInto($entry, $segments, $leaf, $tag);
                    $marked = $entry === null ? null : array_replace($child, [$key => $entry]);
                    break;
                }
            }
        }

        if ($marked === null) {
            return null;
        }
        $node[$segment['name']] = $marked;

        return $node;
    }
}
