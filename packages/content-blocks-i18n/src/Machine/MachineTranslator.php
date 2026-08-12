<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Content\AreaWalker;
use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\TranslatableField;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Storage\TranslationWriter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Drives a machine-translation provider over a block or a whole page, and
 * writes what comes back through the ordinary write path.
 *
 * ---- One code path for both buttons ----
 *
 * "Translate this field" and "translate the page" are the same call with a
 * different work list. That is the point of the batch-shaped
 * {@see TranslationProviderInterface}: no second implementation to keep in step,
 * and translating a page is one provider call rather than 200.
 *
 * ---- What gets translated ----
 *
 * Missing and outdated fields, never the ones already correct — re-translating
 * a field an editor has hand-corrected is the fastest way to make a team turn
 * the feature off. `$overwrite` opts into it explicitly.
 *
 * ---- Results still go through the writer ----
 *
 * A provider's output is untrusted input like any other: it goes through
 * {@see TranslationWriter}, so the translatable-field allow-list, the
 * path-exists check and the source digests all apply. A provider cannot write
 * to a field that is not tagged, and cannot stamp a digest that does not match
 * the text it translated.
 */
final class MachineTranslator
{
    public function __construct(
        private readonly TranslationInspector $inspector,
        private readonly TranslationWriter $writer,
        private readonly TranslationProviderRegistry $providers,
        private readonly TranslationLocales $locales,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string>|null $paths limit to these fields; null means every eligible one
     */
    public function translateBlock(
        Block $block,
        string $locale,
        ?array $paths = null,
        bool $overwrite = false,
        ?string $providerName = null,
    ): TranslationRunResult {
        $view = $this->inspector->inspectBlock($block, $locale);

        if ($view === null) {
            return new TranslationRunResult($locale, $providerName ?? '');
        }

        return $this->run([$block->getId() => ['block' => $block, 'fields' => $view->fields]], $locale, $paths, $overwrite, $providerName);
    }

    public function translateArea(
        ContentArea $area,
        string $locale,
        bool $overwrite = false,
        ?string $providerName = null,
    ): TranslationRunResult {
        $blocks = [];

        foreach (AreaWalker::blocks($area) as $ref) {
            $blocks[$ref->block->getId()] = ['block' => $ref->block, 'fields' => []];
        }

        foreach ($this->inspector->inspectArea($area, $locale) as $view) {
            if (isset($blocks[$view->blockId])) {
                $blocks[$view->blockId]['fields'] = $view->fields;
            }
        }

        return $this->run($blocks, $locale, null, $overwrite, $providerName);
    }

    /**
     * @param array<int, array{block: Block, fields: list<TranslatableField>}> $blocks
     * @param list<string>|null                                                $paths
     */
    private function run(array $blocks, string $locale, ?array $paths, bool $overwrite, ?string $providerName): TranslationRunResult
    {
        $provider = $providerName === null ? $this->providers->getDefault() : $this->providers->get($providerName);
        $source = $this->locales->getSourceLocale();
        $name = $provider::getName();

        if (!$this->locales->isTarget($locale)) {
            return new TranslationRunResult($locale, $name, failed: ['*' => 'unknown_locale']);
        }

        if (!$provider->supports($source, $locale)) {
            return new TranslationRunResult($locale, $name, failed: ['*' => 'unsupported_locale_pair']);
        }

        $requests = [];
        $index = [];
        $skipped = 0;

        foreach ($blocks as $blockId => $entry) {
            foreach ($entry['fields'] as $field) {
                if ($paths !== null && !\in_array($field->path, $paths, true)) {
                    continue;
                }

                if (!$this->shouldTranslate($field, $overwrite)) {
                    ++$skipped;

                    continue;
                }

                // Paths are unique per block, not per page, so the ref the
                // provider echoes back has to name the block too.
                $ref = $blockId . '#' . $field->path;
                $index[$ref] = ['blockId' => $blockId, 'path' => $field->path];

                $requests[] = new TranslationRequest(
                    path: $ref,
                    text: $field->source,
                    format: $field->widget === 'html' ? TranslationRequest::FORMAT_HTML : TranslationRequest::FORMAT_TEXT,
                    label: $this->labelOf($field),
                    blockType: $entry['block']->getType(),
                );
            }
        }

        if ($requests === []) {
            return new TranslationRunResult($locale, $name, skipped: $skipped);
        }

        $job = new TranslationJob($source, $locale);
        $outcomes = $provider->translate($requests, $job);

        // Grouped per block so each block's fields are written in one call —
        // the writer creates at most one row per block that way.
        $byBlock = [];
        $failed = [];

        foreach ($outcomes as $outcome) {
            $target = $index[$outcome->path] ?? null;

            if ($target === null) {
                // A provider that invented a ref: report it rather than guess
                // which field it meant.
                $failed[$outcome->path] = 'unknown_ref';

                continue;
            }

            if (!$outcome->isSuccess()) {
                $failed[$outcome->path] = $outcome->error ?? 'unknown_error';

                continue;
            }

            $byBlock[$target['blockId']][$target['path']] = $outcome->text;
        }

        $translated = [];

        foreach ($byBlock as $blockId => $values) {
            $result = $this->writer->write($blocks[$blockId]['block'], $locale, $values);

            foreach ($result->written as $path) {
                $translated[] = $blockId . '#' . $path;
            }

            foreach ($result->rejected as $path => $reason) {
                $failed[$blockId . '#' . $path] = $reason;
            }
        }

        return new TranslationRunResult($locale, $name, $translated, $failed, $skipped);
    }

    private function shouldTranslate(TranslatableField $field, bool $overwrite): bool
    {
        return match ($field->status) {
            FieldStatus::MISSING, FieldStatus::OUTDATED => true,
            FieldStatus::TRANSLATED => $overwrite,
        };
    }

    /**
     * The field's label as a human would read it — kit labels are translation
     * keys, and "cb_kit.block.card.field.title" is worse context for an engine
     * than no context at all.
     */
    private function labelOf(TranslatableField $field): ?string
    {
        if ($field->label === '') {
            return null;
        }

        return $this->translator->trans($field->label, [], $field->labelDomain);
    }
}
