<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Content\AreaWalker;
use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\TranslatableField;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\ColumnTranslationView;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Storage\TranslationWriteResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Drives a provider over a block or a page. Missing and outdated fields only,
 * and everything it returns goes through the ordinary writer.
 *
 * @see docs/internals/i18n.md#machine-translation-is-a-seam
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
     * @param list<string>|null $paths null means every eligible field
     */
    public function translateBlock(
        Block $block,
        string $locale,
        ?array $paths = null,
        bool $overwrite = false,
        ?string $providerName = null,
    ): TranslationRunResult {
        $view = $this->inspector->inspectBlock($block, $locale);
        $blockId = $block->getId();

        // An unpersisted block has no id to key translations by, and no rows.
        if ($view === null || $blockId === null) {
            return new TranslationRunResult($locale, $providerName ?? '');
        }

        return $this->run([$this->blockEntry($block, $view->fields, $locale)], $locale, $paths, $overwrite, $providerName);
    }

    /**
     * @param list<string>|null $paths null means every eligible field
     */
    public function translateColumn(
        Column $column,
        string $locale,
        ?array $paths = null,
        bool $overwrite = false,
        ?string $providerName = null,
    ): TranslationRunResult {
        $view = $this->inspector->inspectColumn($column, $locale);

        if ($view === null) {
            return new TranslationRunResult($locale, $providerName ?? '');
        }

        return $this->run([$this->columnEntry($column, $view, $locale)], $locale, $paths, $overwrite, $providerName);
    }

    public function translateArea(
        ContentArea $area,
        string $locale,
        bool $overwrite = false,
        ?string $providerName = null,
    ): TranslationRunResult {
        $blocks = [];
        $columns = [];

        foreach (AreaWalker::sections($area) as $section) {
            foreach (AreaWalker::columns($section) as $column) {
                $columns[$column->getId()] = $column;

                foreach (AreaWalker::columnBlocks($column) as $block) {
                    $blocks[$block->getId()] = $block;
                }
            }
        }

        $entries = [];

        foreach ($this->inspector->inspectArea($area, $locale) as $view) {
            if ($view instanceof ColumnTranslationView) {
                if (isset($columns[$view->columnId])) {
                    $entries[] = $this->columnEntry($columns[$view->columnId], $view, $locale);
                }
            } elseif (isset($blocks[$view->blockId])) {
                $entries[] = $this->blockEntry($blocks[$view->blockId], $view->fields, $locale);
            }
        }

        return $this->run($entries, $locale, null, $overwrite, $providerName);
    }

    /**
     * @param list<TranslatableField> $fields
     *
     * @return array{
     *     key: string,
     *     blockType: string|null,
     *     fields: list<TranslatableField>,
     *     write: \Closure(array<string, string|null>): TranslationWriteResult,
     * }
     */
    private function blockEntry(Block $block, array $fields, string $locale): array
    {
        return [
            'key' => (string) $block->getId(),
            'blockType' => $block->getType(),
            'fields' => $fields,
            'write' => fn (array $values): TranslationWriteResult => $this->writer->write($block, $locale, $values),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     blockType: string|null,
     *     fields: list<TranslatableField>,
     *     write: \Closure(array<string, string|null>): TranslationWriteResult,
     * }
     */
    private function columnEntry(Column $column, ColumnTranslationView $view, string $locale): array
    {
        return [
            'key' => $view->key(),
            'blockType' => null,
            'fields' => $view->fields,
            'write' => fn (array $values): TranslationWriteResult => $this->writer->writeColumn($column, $locale, $values),
        ];
    }

    /**
     * Refs are `key#path`: paths are unique per entry, not per page. A block's
     * key is its id, so block refs read as they always did.
     *
     * @param list<array{
     *     key: string,
     *     blockType: string|null,
     *     fields: list<TranslatableField>,
     *     write: \Closure(array<string, string|null>): TranslationWriteResult,
     * }> $entries
     * @param list<string>|null $paths
     */
    private function run(array $entries, string $locale, ?array $paths, bool $overwrite, ?string $providerName): TranslationRunResult
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

        foreach ($entries as $position => $entry) {
            foreach ($entry['fields'] as $field) {
                if ($paths !== null && !\in_array($field->path, $paths, true)) {
                    continue;
                }

                if (!$this->shouldTranslate($field, $overwrite)) {
                    ++$skipped;

                    continue;
                }

                $ref = $entry['key'] . '#' . $field->path;
                $index[$ref] = ['entry' => $position, 'path' => $field->path];

                $requests[] = new TranslationRequest(
                    path: $ref,
                    text: $field->source,
                    format: $field->widget === 'html' ? TranslationRequest::FORMAT_HTML : TranslationRequest::FORMAT_TEXT,
                    label: $this->labelOf($field),
                    blockType: $entry['blockType'],
                );
            }
        }

        if ($requests === []) {
            return new TranslationRunResult($locale, $name, skipped: $skipped);
        }

        $job = new TranslationJob($source, $locale);
        $outcomes = $provider->translate($requests, $job);

        // Grouped per entry so each one's fields are written in one call —
        // the writer creates at most one row per entry that way.
        $byEntry = [];
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

            $byEntry[$target['entry']][$target['path']] = $outcome->text;
        }

        $translated = [];

        foreach ($byEntry as $position => $values) {
            $entry = $entries[$position];
            $result = ($entry['write'])($values);

            foreach ($result->written as $path) {
                $translated[] = $entry['key'] . '#' . $path;
            }

            foreach ($result->rejected as $path => $reason) {
                $failed[$entry['key'] . '#' . $path] = $reason;
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
     * Kit labels are translation keys, and `cb_kit.block.card.field.title` is
     * worse context for an engine than none.
     */
    private function labelOf(TranslatableField $field): ?string
    {
        if ($field->label === '') {
            return null;
        }

        return $this->translator->trans($field->label, [], $field->labelDomain);
    }
}
