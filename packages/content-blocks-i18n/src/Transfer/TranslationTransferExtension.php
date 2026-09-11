<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Transfer;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
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
        $refs = [];
        foreach ($blocks as $ref => $block) {
            $id = $block->getId();
            if ($id !== null) {
                $refs[$id] = $ref;
            }
        }

        if ($refs === []) {
            return [];
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

        return $out === [] ? [] : ['blocks' => $out];
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
