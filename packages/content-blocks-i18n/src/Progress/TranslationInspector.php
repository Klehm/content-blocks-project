<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Progress;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\I18n\Content\AreaWalker;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Field\TranslatableField;
use ContentBlocks\I18n\Field\TranslatableFieldCatalog;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Section\SectionDisplay;
use ContentBlocks\Section\SectionStyleRegistry;
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
        private readonly ?SectionStyleRegistry $styles = null,
    ) {
    }

    /**
     * Everything with something to translate, in reading order: a tabs or
     * accordion section's titles, then its blocks. Empty views are dropped.
     *
     * @return list<BlockTranslationView|ColumnTranslationView>
     */
    public function inspectArea(ContentArea $area, string $locale): array
    {
        $this->store->prefetchArea($area, $locale);

        $views = [];

        foreach (AreaWalker::sections($area) as $sectionIndex => $section) {
            $columns = AreaWalker::columns($section);

            $display = $this->displayOf($section);

            if (SectionDisplay::showsTitles($display)) {
                foreach ($columns as $columnIndex => $column) {
                    $view = $this->inspectColumnRef($column, $locale, $display, $sectionIndex + 1, $columnIndex + 1);

                    if ($view !== null && !$view->isEmpty()) {
                        $views[] = $view;
                    }
                }
            }

            foreach ($columns as $column) {
                foreach (AreaWalker::columnBlocks($column) as $blockIndex => $block) {
                    $view = $this->inspectRef($block, $locale, $sectionIndex + 1, $blockIndex + 1);

                    if ($view !== null && !$view->isEmpty()) {
                        $views[] = $view;
                    }
                }
            }
        }

        return $views;
    }

    /**
     * Null when the column has no id, or its section shows no titles (a
     * grid) — a title nobody reads is not work.
     */
    public function inspectColumn(Column $column, string $locale): ?ColumnTranslationView
    {
        $section = $column->getSection();

        $display = $section === null ? SectionDisplay::GRID : $this->displayOf($section);

        if ($section === null || !SectionDisplay::showsTitles($display)) {
            return null;
        }

        $number = array_search($column, AreaWalker::columns($section), true);

        return $this->inspectColumnRef($column, $locale, $display, 0, $number === false ? 0 : $number + 1);
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
        $payload = $this->store->payloadFor($block, $locale, RenderMode::PREVIEW);

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

    private function inspectColumnRef(Column $column, string $locale, string $display, int $sectionNumber, int $columnNumber): ?ColumnTranslationView
    {
        $columnId = $column->getId();

        if ($columnId === null) {
            return null;
        }

        $source = $this->store->columnSourceLabel($column);
        $fields = [];
        $kind = $display === SectionDisplay::ACCORDION ? 'panel' : 'tab';

        if ($source !== null) {
            $payload = $this->store->columnPayloadFor($column, $locale, RenderMode::PREVIEW);
            $value = $payload['values'][ColumnTranslation::LABEL] ?? null;

            $fields[] = new TranslatableField(
                path: ColumnTranslation::LABEL,
                pattern: ColumnTranslation::LABEL,
                label: 'cb_i18n.workbench.' . $kind . '_title',
                labelDomain: 'content_blocks_i18n',
                widget: 'text',
                source: $source,
                value: \is_string($value) ? $value : null,
                status: match (true) {
                    !\is_string($value) => FieldStatus::MISSING,
                    SourceDigest::matches($source, $payload['digests'][ColumnTranslation::LABEL] ?? null) => FieldStatus::TRANSLATED,
                    default => FieldStatus::OUTDATED,
                },
            );
        }

        return new ColumnTranslationView(
            columnId: $columnId,
            label: $this->translator->trans('cb_i18n.workbench.' . $kind . '_n', ['%n%' => $columnNumber], 'content_blocks_i18n'),
            group: $this->translator->trans('cb_i18n.workbench.' . ($kind === 'panel' ? 'accordion' : 'tabs'), [], 'content_blocks_i18n'),
            sectionId: $column->getSection()?->getId() ?? 0,
            sectionNumber: $sectionNumber,
            columnNumber: $columnNumber,
            fields: $fields,
            progress: TranslationProgress::of($locale, $fields),
        );
    }

    /** The section's own display, over its style preset's, as it renders. */
    private function displayOf(Section $section): string
    {
        $settings = $section->getEffectiveSettings(preferDraft: true);
        $styleName = $settings['styleName'] ?? null;
        $preset = \is_string($styleName) && $styleName !== ''
            ? ($this->styles?->get($styleName)->settings ?? [])
            : [];

        return SectionDisplay::fromSettings($settings + $preset);
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
