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
 * The read model behind progress bars, workbench rows and the bulk work list —
 * one service so they cannot disagree. Always the **draft** view.
 *
 * @see docs/internals/i18n.md#one-walk-one-order
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
     * Every block with something to translate, in reading order. Blocks with
     * no translatable field are dropped, not listed as complete.
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
     * Progress for every target locale, so a switcher can show "DE 40%" before
     * the editor opens it.
     *
     * @return array<string, TranslationProgress>
     */
    public function progressMatrix(ContentArea $area): array
    {
        // One query for all locales; the alternative is one per locale on
        // every page of an admin list.
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
