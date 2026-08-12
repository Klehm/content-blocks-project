<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Storage;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Field\FieldPath;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * The only way a translation value gets written.
 *
 * Every input here is untrusted — an HTTP payload from the workbench, or text a
 * remote machine-translation service produced — so this class is the allow-list,
 * on the same principle that makes a block's own form the whitelist for
 * `Block.data`. Three gates, in order:
 *
 *  1. **the locale must be a configured target.** Not a target, not writable —
 *     that keeps rows from accumulating for locales nobody renders, and stops a
 *     forged payload from seeding one.
 *  2. **the path must address a tagged field**, checked as a *pattern* so
 *     `items[9f2c1a].label` is validated against `items[].label`. An untagged
 *     field is refused at write, not merely ignored at render, so the refusal
 *     reaches the editor instead of quietly doing nothing.
 *  3. **the path must exist in the source data.** A translation for a card that
 *     was deleted, or a field the block type dropped, has nothing to attach to.
 *
 * ---- Empty string vs null ----
 *
 * They mean different things and both are needed:
 *
 *  - `null` **clears** the translation — the field falls back to the source.
 *  - `''` **stores a blank** — the field renders empty in this locale.
 *
 * The difference is not pedantry. A card carrying an optional subtitle in
 * English and none in German needs the blank: clearing would fall back and print
 * the English subtitle on the German page, which is the exact failure a
 * translation feature exists to prevent.
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
     * Writes a batch of values for one block in one locale, into the draft.
     *
     * Draft, always — never straight to published. Translations ride the area's
     * existing Publish/Discard buttons, so an editor reviews a translated page
     * the same way they review any other change, and Discard reverts it.
     *
     * @param array<string, string|null> $values path => text, or null to clear
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
     * Re-stamps the source digest of fields whose translation the editor judges
     * still correct — the "the English changed but the German still says the
     * right thing" action.
     *
     * Without it, staleness would be a flag an editor can only clear by
     * retyping a translation that was already fine, and a signal that costs
     * busywork to dismiss is a signal people learn to ignore.
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
     * Drops every translation of this block in this locale, by emptying the
     * draft payload rather than deleting the row — so Discard can still bring
     * it back, exactly like any other unpublished change.
     */
    public function clear(Block $block, string $locale): void
    {
        $this->store->find($block, $locale)?->setDraftPayload([], []);
    }
}
