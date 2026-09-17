<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Storage;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Section\ColumnSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of the translation rows, plus the prefetch that keeps a page from
 * becoming an N+1. The cold path still works; the cache is per-request.
 *
 * @see docs/internals/i18n.md#why-a-side-table-not-an-envelope-in-blockdata
 */
final class TranslationStore implements ResetInterface
{
    /** @var array<string, BlockTranslation|null> */
    private array $rows = [];

    /** @var array<string, ColumnTranslation|null> */
    private array $columnRows = [];

    /** @var array<string, true> */
    private array $warmed = [];

    public function __construct(
        private readonly BlockTranslationRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly ?ColumnTranslationRepository $columnRepository = null,
    ) {
    }

    /**
     * Every row of an area in one query. Null warms all locales at once, which
     * is what the workbench and the progress matrix want.
     */
    public function prefetchArea(ContentArea $area, ?string $locale = null): void
    {
        $areaId = $area->getId();

        if ($areaId === null) {
            return;
        }

        $key = $areaId . '|' . ($locale ?? '*');

        if (isset($this->warmed[$key])) {
            return;
        }

        foreach ($this->repository->findForArea($area, $locale) as $row) {
            $blockId = $row->getBlock()?->getId();

            if ($blockId !== null) {
                $this->rows[$blockId . '|' . $row->getLocale()] = $row;
            }
        }

        foreach ($this->columnRepository?->findForArea($area, $locale) ?? [] as $row) {
            $columnId = $row->getColumn()?->getId();

            if ($columnId !== null) {
                $this->columnRows[$columnId . '|' . $row->getLocale()] = $row;
            }
        }

        $this->warmed[$key] = true;

        // Blocks with no row are remembered as *known absent*, or every
        // untranslated block still costs a query.
        if ($locale === null) {
            return;
        }

        foreach ($area->getSections() as $section) {
            foreach ($section->getColumns() as $column) {
                $columnId = $column->getId();

                if ($columnId !== null && !\array_key_exists($columnId . '|' . $locale, $this->columnRows)) {
                    $this->columnRows[$columnId . '|' . $locale] = null;
                }

                foreach ($column->getBlocks() as $block) {
                    $blockId = $block->getId();

                    if ($blockId !== null && !\array_key_exists($blockId . '|' . $locale, $this->rows)) {
                        $this->rows[$blockId . '|' . $locale] = null;
                    }
                }
            }
        }
    }

    public function find(Block $block, string $locale): ?BlockTranslation
    {
        $blockId = $block->getId();

        if ($blockId === null) {
            return null;
        }

        $key = $blockId . '|' . $locale;

        if (\array_key_exists($key, $this->rows)) {
            return $this->rows[$key];
        }

        return $this->rows[$key] = $this->repository->findOneFor($block, $locale);
    }

    /**
     * The row for this block and locale, created (and persisted, unflushed) if
     * it does not exist yet. Callers flush.
     */
    public function findOrCreate(Block $block, string $locale): BlockTranslation
    {
        $existing = $this->find($block, $locale);

        if ($existing !== null) {
            return $existing;
        }

        $row = new BlockTranslation($block, $locale);
        $this->em->persist($row);

        $blockId = $block->getId();

        if ($blockId !== null) {
            $this->rows[$blockId . '|' . $locale] = $row;
        }

        return $row;
    }

    /**
     * PREVIEW takes draft-or-published, PUBLIC published-only — the same rule
     * the core applies to the block's own data. Any other pairing is a bug.
     *
     * @see docs/internals/i18n.md#draft-and-published-mirror-block-exactly
     *
     * @return array{
     *     values: array<string, mixed>,
     *     digests: array<string, string>,
     * }
     */
    public function payloadFor(Block $block, string $locale, RenderMode $mode): array
    {
        $row = $this->find($block, $locale);

        if ($row === null) {
            return ['values' => [], 'digests' => []];
        }

        if ($mode === RenderMode::PREVIEW) {
            return ['values' => $row->getEffectiveValues(), 'digests' => $row->getEffectiveDigests()];
        }

        return [
            'values' => $row->getPublishedValues() ?? [],
            'digests' => $row->getPublishedDigests() ?? [],
        ];
    }

    /**
     * What the editor translates *from* — draft-wins, which also keeps the
     * digest measured against the text the translator was actually shown.
     *
     * @return array<string, mixed>
     */
    public function sourceDataOf(Block $block): array
    {
        return $block->getDraftData() ?? $block->getPublishedData() ?? [];
    }

    /** Forget a row, so the next read goes back to the database. */
    public function evict(Block $block, string $locale): void
    {
        $blockId = $block->getId();

        if ($blockId !== null) {
            unset($this->rows[$blockId . '|' . $locale]);
        }

        $this->warmed = [];
    }

    // ---------- Columns ----------

    public function findColumn(Column $column, string $locale): ?ColumnTranslation
    {
        $columnId = $column->getId();

        if ($columnId === null || $this->columnRepository === null) {
            return null;
        }

        $key = $columnId . '|' . $locale;

        if (\array_key_exists($key, $this->columnRows)) {
            return $this->columnRows[$key];
        }

        return $this->columnRows[$key] = $this->columnRepository->findOneFor($column, $locale);
    }

    /** Persisted, unflushed, like {@see self::findOrCreate()}. */
    public function findOrCreateColumn(Column $column, string $locale): ColumnTranslation
    {
        $existing = $this->findColumn($column, $locale);

        if ($existing !== null) {
            return $existing;
        }

        $row = new ColumnTranslation($column, $locale);
        $this->em->persist($row);

        $columnId = $column->getId();

        if ($columnId !== null) {
            $this->columnRows[$columnId . '|' . $locale] = $row;
        }

        return $row;
    }

    /**
     * Same mode rule as {@see self::payloadFor()}.
     *
     * @return array{
     *     values: array<string, mixed>,
     *     digests: array<string, string>,
     * }
     */
    public function columnPayloadFor(Column $column, string $locale, RenderMode $mode): array
    {
        $row = $this->findColumn($column, $locale);

        if ($row === null) {
            return ['values' => [], 'digests' => []];
        }

        if ($mode === RenderMode::PREVIEW) {
            return ['values' => $row->getEffectiveValues(), 'digests' => $row->getEffectiveDigests()];
        }

        return [
            'values' => $row->getPublishedValues() ?? [],
            'digests' => $row->getPublishedDigests() ?? [],
        ];
    }

    /** The tab title a translator works from: draft wins, as for blocks. */
    public function columnSourceLabel(Column $column): ?string
    {
        return ColumnSettings::label($column->getEffectiveSettings(preferDraft: true));
    }

    public function reset(): void
    {
        $this->rows = [];
        $this->columnRows = [];
        $this->warmed = [];
    }
}
