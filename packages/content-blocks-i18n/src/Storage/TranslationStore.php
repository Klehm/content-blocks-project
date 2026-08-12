<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Storage;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\Rendering\RenderMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of the translation rows, plus the prefetch that keeps a translated
 * page from turning into an N+1.
 *
 * ---- The prefetch ----
 *
 * A side table buys queryability at the cost of a join per block. Rendering a
 * 40-block page in French would issue 40 SELECTs if each
 * {@see \ContentBlocks\I18n\Rendering\TranslationBlockDataResolver} call went to
 * the database on its own. So the renderer decorator warms this store with one
 * query for the whole area before the pipeline runs
 * (see {@see \ContentBlocks\I18n\Rendering\PrefetchingBlockRenderer}), and the
 * resolver reads from memory.
 *
 * The cold path still works — a block rendered outside a warmed area falls back
 * to a single lookup — because a seam that only works when someone remembered to
 * warm it is a seam that breaks in the one flow nobody tested.
 *
 * ---- Lifetime ----
 *
 * The cache is per-request: {@see ResetInterface} clears it between requests of
 * a long-running worker, where a stale entry would otherwise serve one page's
 * translations to the next.
 */
final class TranslationStore implements ResetInterface
{
    /** @var array<string, BlockTranslation|null> */
    private array $rows = [];

    /** @var array<string, true> */
    private array $warmed = [];

    public function __construct(
        private readonly BlockTranslationRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Loads every translation row of an area in one query.
     *
     * Passing null for $locale warms all locales at once — what the workbench
     * and the progress view want, since both report on the whole matrix.
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

        $this->warmed[$key] = true;

        // Blocks with no row must be remembered as *known absent*, otherwise
        // every untranslated block on the page — the common case early in a
        // translation project — still costs a query.
        if ($locale === null) {
            return;
        }

        foreach ($area->getSections() as $section) {
            foreach ($section->getColumns() as $column) {
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
     * The values/digests a given render mode should see.
     *
     * PREVIEW takes draft-or-published and PUBLIC takes published-only —
     * deliberately the same rule {@see \ContentBlocks\Rendering\CoreBlockDataResolver}
     * applies to the block's own data. Any other pairing produces the two bugs
     * this design exists to avoid: a French heading going public against an
     * English source that is still an unpublished draft, or the builder preview
     * showing a translation the editor cannot see themselves editing.
     *
     * @return array{values: array<string, mixed>, digests: array<string, string>}
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
     * What the editor is translating *from*: the in-flight edit if there is one.
     *
     * Same draft-wins rule {@see \ContentBlocks\Section\SectionCloner} uses, and
     * for the same reason — an editor's unsaved intent is more representative
     * than the last published value. It also keeps the digest honest: the
     * staleness flag is measured against the text the translator was shown.
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

    public function reset(): void
    {
        $this->rows = [];
        $this->warmed = [];
    }
}
