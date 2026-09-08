<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Storage;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Field\FieldPath;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * The only way a translation value gets written, and therefore the allow-list.
 * Three gates: configured locale, tagged pattern, path exists in the source.
 *
 * @see docs/internals/i18n.md#null-clears-empty-string-stores
 */
final class TranslationWriter
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly TranslatableFieldsInterface $translatableFields,
        private readonly TranslationLocales $locales,
    ) {
    }

    /**
     * Into the draft, always — translations ride the area's own Publish and
     * Discard.
     *
     * @param array<string, string|null> $values path => text, null to clear
     */
    public function write(Block $block, string $locale, array $values): TranslationWriteResult
    {
        if (!$this->locales->isTarget($locale)) {
            return new TranslationWriteResult(rejected: array_fill_keys(array_keys($values), 'unknown_locale'));
        }

        $sourceData = $this->store->sourceDataOf($block);
        $allowed = $this->translatableFields->forBlockType($block->getType(), $sourceData);

        $written = [];
        $cleared = [];
        $rejected = [];
        $row = null;

        foreach ($values as $path => $value) {
            $path = (string) $path;

            if (!FieldPath::matchesAny($path, $allowed)) {
                $rejected[$path] = 'not_translatable';

                continue;
            }

            if (!FieldPath::has($sourceData, $path)) {
                $rejected[$path] = 'unknown_path';

                continue;
            }

            // Created lazily so a wholly-rejected batch leaves no empty row
            // behind — and so a GET-shaped mistake cannot litter the table.
            $row ??= $this->store->findOrCreate($block, $locale);

            if ($value === null) {
                $row->removeDraftValue($path);
                $cleared[] = $path;

                continue;
            }

            $row->setDraftValue($path, $value, SourceDigest::of(FieldPath::read($sourceData, $path)));
            $written[] = $path;
        }

        return new TranslationWriteResult($written, $cleared, $rejected);
    }

    /**
     * Re-stamps the digest where the translation is still right. Without it,
     * a signal that costs busywork to dismiss is one people learn to ignore.
     *
     * @param list<string> $paths
     */
    public function markUpToDate(Block $block, string $locale, array $paths): TranslationWriteResult
    {
        $row = $this->store->find($block, $locale);

        if ($row === null) {
            return new TranslationWriteResult(rejected: array_fill_keys($paths, 'no_translation'));
        }

        $sourceData = $this->store->sourceDataOf($block);
        $values = $row->getEffectiveValues();

        $written = [];
        $rejected = [];

        foreach ($paths as $path) {
            if (!\array_key_exists($path, $values) || !FieldPath::has($sourceData, $path)) {
                $rejected[$path] = 'no_translation';

                continue;
            }

            $row->setDraftValue($path, $values[$path], SourceDigest::of(FieldPath::read($sourceData, $path)));
            $written[] = $path;
        }

        return new TranslationWriteResult($written, rejected: $rejected);
    }

    /**
     * Empties the draft payload rather than deleting the row, so Discard can
     * still bring it back like any other unpublished change.
     */
    public function clear(Block $block, string $locale): void
    {
        $this->store->find($block, $locale)?->setDraftPayload([], []);
    }
}
