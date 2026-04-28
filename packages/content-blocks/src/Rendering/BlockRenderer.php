<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Security\AccessCheckerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders a ContentArea for the front-end.
 *
 * In PUBLIC mode: only published, non-deleted content, ordered by position.
 * In PREVIEW mode: draft data merged in, soft-deleted entities included with a
 * marker, ordered by previewPosition. The PREVIEW HTML also embeds the overlay
 * JS bridge so the parent admin window can react to user interactions.
 *
 * Mode is auto-detected from the current request: query `cb_preview=1`
 * combined with the AccessChecker's canEdit() granting access switches to
 * PREVIEW. Anything else falls through to PUBLIC.
 */
final class BlockRenderer
{
    public const QUERY_PARAM = 'cb_preview';
    private const RENDER_TEMPLATE = '@ContentBlocks/render/content_area.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly SectionDecoratorCollection $sectionDecorators,
        private readonly SectionSettingsDefaults $settingsDefaults,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function render(ContentArea $area, ?RenderMode $forceMode = null): string
    {
        $mode = $forceMode ?? $this->resolveMode($area);
        $sections = $this->buildSectionTree($area, $mode);

        $blockTypes = [];
        if ($mode === RenderMode::PREVIEW) {
            foreach ($this->blockTypeRegistry->getChoices() as $type => $label) {
                $blockTypes[] = [
                    'type' => $type,
                    'label' => $label instanceof TranslatableInterface
                        ? $label->trans($this->translator)
                        : $this->translator->trans((string) $label),
                ];
            }
        }

        return $this->twig->render(self::RENDER_TEMPLATE, [
            'mode' => $mode,
            'sections' => $sections,
            'blockTypes' => $blockTypes,
        ]);
    }

    public function resolveMode(ContentArea $area): RenderMode
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return RenderMode::PUBLIC;
        }

        if ($request->query->get(self::QUERY_PARAM) !== '1') {
            return RenderMode::PUBLIC;
        }

        if (!$this->accessChecker->canEdit($area)) {
            return RenderMode::PUBLIC;
        }

        return RenderMode::PREVIEW;
    }

    /**
     * @return list<array{id: ?int, layout: string, deleted: bool, columns: list<array<string, mixed>>}>
     */
    private function buildSectionTree(ContentArea $area, RenderMode $mode): array
    {
        $sections = $area->getSections()->toArray();

        if ($mode === RenderMode::PUBLIC) {
            $sections = array_values(array_filter($sections, fn (Section $s) => !$s->isDeleted()));
            usort($sections, fn (Section $a, Section $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $out = [];
        foreach ($sections as $section) {
            $sectionDeleted = $section->isDeleted();
            $settings = $section->getEffectiveSettings(preferDraft: $mode === RenderMode::PREVIEW);
            // Strip default-equal entries so the rendered markup stays
            // clean: a section saved with the framework-provided default
            // (e.g. backgroundColor=#ffffff) won't get an inline style for
            // it, only user-overridden values do.
            $settings = $this->settingsDefaults->withoutDefaults($settings);
            $decoration = $this->sectionDecorators->decorate($settings, $section);
            $out[] = [
                'id' => $section->getId(),
                'layout' => $section->getLayout(),
                'deleted' => $sectionDeleted,
                'extraClasses' => $decoration->classString(),
                'inlineStyle' => $decoration->styleString(),
                'extraAttributes' => $decoration->attributes,
                'columns' => $this->buildColumnTree($section, $mode, $sectionDeleted),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: ?int, preset: string, deleted: bool, blocks: list<array<string, mixed>>}>
     */
    private function buildColumnTree(Section $section, RenderMode $mode, bool $parentDeleted): array
    {
        $columns = $section->getColumns()->toArray();

        if ($mode === RenderMode::PUBLIC) {
            $columns = array_values(array_filter($columns, fn (Column $c) => !$c->isDeleted()));
            usort($columns, fn (Column $a, Column $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($columns, fn (Column $a, Column $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $out = [];
        foreach ($columns as $column) {
            $columnDeleted = $parentDeleted || $column->isDeleted();
            $out[] = [
                'id' => $column->getId(),
                'preset' => $column->getPreset(),
                'deleted' => $columnDeleted,
                'blocks' => $this->buildBlockList($column, $mode, $columnDeleted),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: ?int, type: string, data: array<string, mixed>, viewTemplate: ?string, deleted: bool}>
     */
    private function buildBlockList(Column $column, RenderMode $mode, bool $parentDeleted): array
    {
        $blocks = $column->getBlocks()->toArray();

        if ($mode === RenderMode::PUBLIC) {
            $blocks = array_values(array_filter(
                $blocks,
                fn (Block $b) => !$b->isDeleted() && $b->getPublishedData() !== null,
            ));
            usort($blocks, fn (Block $a, Block $b) => $a->getPosition() <=> $b->getPosition());
        } else {
            usort($blocks, fn (Block $a, Block $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        }

        $out = [];
        foreach ($blocks as $block) {
            $blockType = $this->blockTypeRegistry->has($block->getType())
                ? $this->blockTypeRegistry->get($block->getType())
                : null;

            $data = $mode === RenderMode::PREVIEW
                ? ($block->getDraftData() ?? $block->getPublishedData() ?? [])
                : ($block->getPublishedData() ?? []);

            $out[] = [
                'id' => $block->getId(),
                'type' => $block->getType(),
                'data' => $data,
                'viewTemplate' => $blockType?->getViewTemplate(),
                'deleted' => $parentDeleted || $block->isDeleted(),
            ];
        }

        return $out;
    }
}
