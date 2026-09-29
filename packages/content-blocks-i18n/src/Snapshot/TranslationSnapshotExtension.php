<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Snapshot;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Field\FieldPath;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\Snapshot\SnapshotExtensionInterface;

/**
 * Translations through a section template and the clipboard. Paths are kept
 * by entry position, since a pasted block gets new entry ids.
 *
 * @see docs/internals/i18n.md#translations-in-templates-and-the-clipboard
 */
final class TranslationSnapshotExtension implements SnapshotExtensionInterface
{
    public const KEY = 'content-blocks/i18n';

    public function __construct(
        private readonly BlockTranslationRepository $repository,
        private readonly TranslationStore $store,
        private readonly TranslationWriter $writer,
        private readonly ?ColumnTranslationRepository $columnRepository = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function capture(array $blocks, array $columns): array
    {
        $out = [];

        $refs = self::refsById($blocks);
        foreach ($refs === [] ? [] : $this->repository->findForBlockIds(array_keys($refs)) as $row) {
            $block = $row->getBlock();
            $ref = $refs[$block?->getId() ?? 0] ?? null;
            if ($block === null || $ref === null) {
                continue;
            }

            $entry = $this->captureBlockRow($row, $this->store->sourceDataOf($block));
            if ($entry !== null) {
                $out['blocks'][$ref][$row->getLocale()] = $entry;
            }
        }

        $refs = self::refsById($columns);
        $rows = $refs === [] ? [] : ($this->columnRepository?->findForColumnIds(array_keys($refs)) ?? []);
        foreach ($rows as $row) {
            $ref = $refs[$row->getColumn()?->getId() ?? 0] ?? null;
            $entry = self::captureColumnRow($row);
            if ($ref !== null && $entry !== null) {
                $out['columns'][$ref][$row->getLocale()] = $entry;
            }
        }

        return $out;
    }

    public function restore(array $blocks, array $columns, array $fragment): void
    {
        foreach (self::entries($fragment['blocks'] ?? null) as $ref => $locales) {
            $block = $blocks[$ref] ?? null;
            if ($block === null) {
                continue;
            }
            $data = $this->store->sourceDataOf($block);

            foreach (self::entries($locales) as $locale => $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                [$values, $digests] = self::rebase($entry, $data);
                if ($values !== []) {
                    $this->writer->restore($block, $locale, $values, $digests);
                }
            }
        }

        foreach (self::entries($fragment['columns'] ?? null) as $ref => $locales) {
            $column = $columns[$ref] ?? null;
            if ($column === null) {
                continue;
            }

            foreach (self::entries($locales) as $locale => $entry) {
                if (!\is_array($entry) || !\is_array($entry['values'] ?? null)) {
                    continue;
                }
                $this->writer->restoreColumn(
                    $column,
                    $locale,
                    $entry['values'][ColumnTranslation::LABEL] ?? null,
                    \is_array($entry['digests'] ?? null) ? ($entry['digests'][ColumnTranslation::LABEL] ?? null) : null,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $sourceData
     *
     * @return array{
     *     values: array<string, mixed>,
     *     digests: array<string, mixed>,
     * }|null
     */
    private function captureBlockRow(BlockTranslation $row, array $sourceData): ?array
    {
        $digests = $row->getEffectiveDigests();
        $values = [];
        $kept = [];

        foreach ($row->getEffectiveValues() as $path => $value) {
            $positional = \is_string($value) ? FieldPath::toPositional((string) $path, $sourceData) : null;
            if ($positional === null) {
                continue;
            }
            $values[$positional] = $value;
            if (isset($digests[$path])) {
                $kept[$positional] = $digests[$path];
            }
        }

        return $values === [] ? null : ['values' => $values, 'digests' => $kept];
    }

    /**
     * @return array{
     *     values: array<string, mixed>,
     *     digests: array<string, mixed>,
     * }|null
     */
    private static function captureColumnRow(ColumnTranslation $row): ?array
    {
        $label = $row->getEffectiveValues()[ColumnTranslation::LABEL] ?? null;
        if (!\is_string($label)) {
            return null;
        }

        $digest = $row->getEffectiveDigests()[ColumnTranslation::LABEL] ?? null;

        return [
            'values' => [ColumnTranslation::LABEL => $label],
            'digests' => $digest === null ? [] : [ColumnTranslation::LABEL => $digest],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $data  the copy's own
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function rebase(array $entry, array $data): array
    {
        $values = [];
        $digests = [];
        $capturedDigests = \is_array($entry['digests'] ?? null) ? $entry['digests'] : [];

        foreach (self::entries($entry['values'] ?? null) as $path => $value) {
            $real = FieldPath::fromPositional($path, $data);
            if ($real === null) {
                continue;
            }
            $values[$real] = $value;
            $digests[$real] = $capturedDigests[$path] ?? null;
        }

        return [$values, $digests];
    }

    /**
     * @template T of Block|Column
     *
     * @param array<string, T> $entities
     *
     * @return array<int, string> id => ref
     */
    private static function refsById(array $entities): array
    {
        $out = [];
        foreach ($entities as $ref => $entity) {
            $id = $entity->getId();
            if ($id !== null) {
                $out[$id] = (string) $ref;
            }
        }

        return $out;
    }

    /**
     * String-keyed entries of an untrusted array; anything else is empty.
     *
     * @return array<string, mixed>
     */
    private static function entries(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && $key !== '') {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
