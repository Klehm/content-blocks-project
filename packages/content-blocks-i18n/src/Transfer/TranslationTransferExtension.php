<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Transfer;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Content\AreaWalker;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\Transfer\AssetRewriter;
use ContentBlocks\Transfer\AssetTokenizer;
use ContentBlocks\Transfer\ContentAreaTransferExtensionInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carries translation rows through export and import — the other half of the
 * cost the side-table schema pays, beside the clone observer.
 *
 * @see docs/internals/i18n.md#translations-in-an-export
 */
final class TranslationTransferExtension implements ContentAreaTransferExtensionInterface
{
    /** Namespaced like the package, so nothing else can claim it. */
    public const KEY = 'content-blocks/i18n';

    public function __construct(
        private readonly BlockTranslationRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly ?ColumnTranslationRepository $columnRepository = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param array<string, Block> $blocks
     *
     * @return array<string, mixed>
     */
    public function export(ContentArea $area, array $blocks, AssetTokenizer $assets): array
    {
        $fragment = $this->exportColumns($area, $assets);

        $refs = [];
        foreach ($blocks as $ref => $block) {
            $id = $block->getId();
            if ($id !== null) {
                $refs[$id] = $ref;
            }
        }

        if ($refs === []) {
            return $fragment;
        }

        $out = [];
        foreach ($this->repository->findForBlockIds(array_keys($refs)) as $row) {
            $values = $row->getEffectiveValues();
            $id = $row->getBlock()?->getId();

            if ($values === [] || $id === null || !isset($refs[$id])) {
                continue;
            }

            $out[$refs[$id]][$row->getLocale()] = [
                // Digests travel beside the values they were captured against:
                // recomputing here would report every stale field as fresh.
                'values' => $assets->tokenize($values),
                'digests' => $row->getEffectiveDigests(),
            ];
        }

        return $out === [] ? $fragment : ['blocks' => $out] + $fragment;
    }

    /**
     * Keyed `s{i}.c{j}`, the exporter's own reading order: the core hands
     * extensions block refs only, and a column's position is its identity.
     *
     * @return array{columns?: array<string, array<string, mixed>>}
     */
    private function exportColumns(ContentArea $area, AssetTokenizer $assets): array
    {
        $refs = [];
        foreach (AreaWalker::sections($area) as $i => $section) {
            foreach (AreaWalker::columns($section) as $j => $column) {
                $id = $column->getId();
                if ($id !== null) {
                    $refs[$id] = 's' . $i . '.c' . $j;
                }
            }
        }

        $out = [];
        foreach ($this->columnRepository?->findForColumnIds(array_keys($refs)) ?? [] as $row) {
            $values = $row->getEffectiveValues();
            $id = $row->getColumn()?->getId();

            if ($values === [] || $id === null || !isset($refs[$id])) {
                continue;
            }

            $out[$refs[$id]][$row->getLocale()] = [
                'values' => $assets->tokenize($values),
                'digests' => $row->getEffectiveDigests(),
            ];
        }

        return $out === [] ? [] : ['columns' => $out];
    }

    /**
     * Rows land in the draft, like every other write of this package — the
     * area's next Publish commits them, Discard drops them.
     *
     * @param array<string, Block> $blocks
     * @param array<string, mixed> $fragment
     */
    public function import(ContentArea $area, array $blocks, array $fragment, AssetRewriter $assets): void
    {
        $this->importColumns($area, $fragment['columns'] ?? null, $assets);

        $rows = $fragment['blocks'] ?? null;
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $ref => $byLocale) {
            $block = is_string($ref) ? ($blocks[$ref] ?? null) : null;

            // A block the importer skipped takes its translations with it.
            if ($block === null || !is_array($byLocale)) {
                continue;
            }

            foreach ($byLocale as $locale => $entry) {
                if (!is_string($locale) || $locale === '' || !is_array($entry)) {
                    continue;
                }

                $values = $this->stringKeyed($assets->rewrite($entry['values'] ?? null));
                if ($values === []) {
                    continue;
                }

                $translation = new BlockTranslation($block, $locale);
                $translation->setDraftPayload($values, $this->digests($entry['digests'] ?? null));
                $this->em->persist($translation);
            }
        }
    }

    /**
     * The importer places the n-th payload section and column at preview
     * position n, which is what the export ref counted.
     */
    private function importColumns(ContentArea $area, mixed $rows, AssetRewriter $assets): void
    {
        if (!is_array($rows)) {
            return;
        }

        $columns = [];
        foreach ($area->getSections() as $section) {
            if ($section->isDeleted()) {
                continue;
            }
            foreach ($section->getColumns() as $column) {
                if (!$column->isDeleted()) {
                    $columns['s' . $section->getPreviewPosition() . '.c' . $column->getPreviewPosition()] = $column;
                }
            }
        }

        foreach ($rows as $ref => $byLocale) {
            $column = is_string($ref) ? ($columns[$ref] ?? null) : null;

            if ($column === null || !is_array($byLocale)) {
                continue;
            }

            foreach ($byLocale as $locale => $entry) {
                if (!is_string($locale) || $locale === '' || !is_array($entry)) {
                    continue;
                }

                $values = array_intersect_key(
                    $this->stringKeyed($assets->rewrite($entry['values'] ?? null)),
                    [ColumnTranslation::LABEL => true],
                );
                if (!is_string($values[ColumnTranslation::LABEL] ?? null)) {
                    continue;
                }

                $translation = new ColumnTranslation($column, $locale);
                $translation->setDraftPayload($values, $this->digests($entry['digests'] ?? null));
                $this->em->persist($translation);
            }
        }
    }

    /**
     * The payload is a file that travelled, so both maps are re-typed rather
     * than trusted.
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function digests(mixed $value): array
    {
        $out = [];
        foreach ($this->stringKeyed($value) as $key => $item) {
            if (is_string($item)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
