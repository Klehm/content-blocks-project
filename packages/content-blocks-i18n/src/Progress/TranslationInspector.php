<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Progress;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Content\AreaWalker;
use ContentBlocks\I18n\Field\TranslatableFieldCatalog;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Storage\TranslationStore;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Answers "where does this content stand, per locale" — the read model behind
 * the progress bars, the workbench rows, and the bulk translator's work list.
 *
 * One service for all three so they cannot disagree. A progress bar computed by
 * different code from the list it summarizes is a bug generator: the bar says
 * 100%, the list still shows four empty rows, and neither is obviously wrong.
 *
 * Everything reported here is the **draft** view — draft-or-published source,
 * draft-or-published translations. That is what an editor is working on; a
 * published-state report would tell them their unsaved page is incomplete.
 */
final class TranslationInspector
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly TranslatableFieldCatalog $catalog,
        private readonly TranslationLocales $locales,
        private readonly BlockTypeRegistry $blockTypes,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Every block of the area that has something to translate, in reading order.
     *
     * Blocks with no translatable field are dropped rather than listed as
     * complete: a divider does not belong in a translation list, and leaving it
     * in would bury the rows that need work.
     *
     * @return list<BlockTranslationView>
     */
    public function inspectArea(ContentArea $area, string $locale): array
    {
        $this->store->prefetchArea($area, $locale);

        $views = [];

        foreach (AreaWalker::blocks($area) as $ref) {
            $view = $this->inspectRef($ref->block, $locale, $ref->sectionNumber, $ref->blockNumber);

            if ($view !== null && !$view->isEmpty()) {
                $views[] = $view;
            }
        }

        return $views;
    }

    public function inspectBlock(Block $block, string $locale): ?BlockTranslationView
    {
        return $this->inspectRef($block, $locale, 0, 0);
    }

    public function progressForArea(ContentArea $area, string $locale): TranslationProgress
    {
        $total = new TranslationProgress($locale);

        foreach ($this->inspectArea($area, $locale) as $view) {
            $total = $total->plus($view->progress);
        }

        return $total;
    }

    /**
     * Progress for every configured target locale — the matrix a language
     * switcher decorates itself with, so an editor sees "DE 40%" before
     * choosing to open it.
     *
     * @return array<string, TranslationProgress>
     */
    public function progressMatrix(ContentArea $area): array
    {
        // One query for all locales, then the per-locale passes read from
        // memory; the alternative is one query per locale on every page of an
        // admin list.
        $this->store->prefetchArea($area);

        $out = [];

        foreach ($this->locales->getTargetLocales() as $locale) {
            $out[$locale] = $this->progressForArea($area, $locale);
        }

        return $out;
    }

    private function inspectRef(Block $block, string $locale, int $sectionNumber, int $blockNumber): ?BlockTranslationView
    {
        $blockId = $block->getId();

        if ($blockId === null) {
            return null;
        }

        $sourceData = $this->store->sourceDataOf($block);
        $payload = $this->store->payloadFor($block, $locale, \ContentBlocks\Rendering\RenderMode::PREVIEW);

        $fields = $this->catalog->build($block->getType(), $sourceData, $payload['values'], $payload['digests']);

        return new BlockTranslationView(
            blockId: $blockId,
            blockType: $block->getType(),
            blockLabel: $this->labelOf($block->getType()),
            sectionId: $block->getColumn()?->getSection()?->getId() ?? 0,
            sectionNumber: $sectionNumber,
            blockNumber: $blockNumber,
            fields: $fields,
            progress: TranslationProgress::of($locale, $fields),
        );
    }

    private function labelOf(string $type): string
    {
        if (!$this->blockTypes->has($type)) {
            return $type;
        }

        $label = $this->blockTypes->get($type)::getLabel();

        return $label instanceof TranslatableInterface
            ? $label->trans($this->translator)
            : $this->translator->trans((string) $label);
    }
}
