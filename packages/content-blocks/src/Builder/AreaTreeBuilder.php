<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a draft area into the outline the tree panel draws. `cb-tree` is the
 * other end.
 *
 * @see docs/internals/frontend.md#the-tree-is-a-second-view-not-a-second-state
 */
final class AreaTreeBuilder
{
    public function __construct(
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The draft outline: `previewPosition` order, soft-deleted nodes pruned —
     * the tree describes what the builder shows, not what PUBLIC renders.
     *
     * @return array{
     *     areaId: int|null,
     *     sections: list<array{
     *         id: int|null,
     *         layout: string,
     *         label: string,
     *         columns: list<array{
     *             id: int|null,
     *             preset: string,
     *             label: string,
     *             blocks: list<array<string, mixed>>,
     *         }>,
     *     }>,
     * }
     */
    public function build(ContentArea $area): array
    {
        $sections = [];
        foreach ($this->ordered($area->getSections()->toArray()) as $index => $section) {
            $sections[] = $this->buildSection($section, $index);
        }

        return [
            'areaId' => $area->getId(),
            'sections' => $sections,
        ];
    }

    /**
     * @return array{
     *     id: int|null,
     *     layout: string,
     *     label: string,
     *     columns: list<array{
     *         id: int|null,
     *         preset: string,
     *         label: string,
     *         blocks: list<array<string, mixed>>,
     *     }>,
     * }
     */
    private function buildSection(Section $section, int $index): array
    {
        $layout = $section->getLayout();
        $columns = [];
        foreach ($this->ordered($section->getColumns()->toArray()) as $columnIndex => $column) {
            $columns[] = $this->buildColumn($column, $columnIndex);
        }

        return [
            'id' => $section->getId(),
            'layout' => $layout,
            'label' => $this->translator->trans('cb.section.label', [
                '%index%' => $index + 1,
                '%layout%' => $this->translator->trans('cb.section.layout.' . $layout, [], 'content_blocks'),
            ], 'content_blocks'),
            'columns' => $columns,
        ];
    }

    /**
     * @return array{
     *     id: int|null,
     *     preset: string,
     *     label: string,
     *     blocks: list<array<string, mixed>>,
     * }
     */
    private function buildColumn(Column $column, int $index): array
    {
        $blocks = [];
        foreach ($this->ordered($column->getBlocks()->toArray()) as $block) {
            $blocks[] = $this->buildBlock($block);
        }

        return [
            'id' => $column->getId(),
            'preset' => $column->getPreset(),
            'label' => $this->translator->trans(
                'cb.builder.tree.column',
                ['%index%' => $index + 1],
                'content_blocks',
            ),
            'blocks' => $blocks,
        ];
    }

    /**
     * `label` is what the row reads; the type is carried by `icon`, which is
     * trusted block-author SVG or null for the consumer's fallback glyph.
     *
     * @see docs/internals/blocks.md#labels-and-icons-cross-a-trust-boundary
     *
     * @return array{
     *     id: int|null,
     *     type: string,
     *     typeLabel: string,
     *     label: string,
     *     kind: string,
     *     icon: string|null,
     *     missing: bool,
     * }
     */
    private function buildBlock(Block $block): array
    {
        $type = $block->getType();

        // An unregistered type still earns a row: the outline must show
        // where the holes are, not silently omit them.
        if (!$this->blockTypeRegistry->has($type)) {
            return [
                'id' => $block->getId(),
                'type' => $type,
                'typeLabel' => $type,
                'label' => $type,
                'kind' => BlockPreviewHint::KIND_GENERIC,
                'icon' => null,
                'missing' => true,
            ];
        }

        $blockType = $this->blockTypeRegistry->get($type);
        $typeLabel = $this->labelOf($blockType::getLabel());
        $hint = null;

        if ($blockType instanceof BlockPreviewHintInterface) {
            // A hint reads stored data of unknown age. One badly-shaped row
            // must cost that row its summary, never the whole tree.
            try {
                $hint = $blockType->previewHint($block->getDraftData() ?? $block->getPublishedData() ?? []);
            } catch (\Throwable) {
                $hint = null;
            }
        }
        $hint ??= BlockPreviewHint::generic();

        return [
            'id' => $block->getId(),
            'type' => $type,
            'typeLabel' => $typeLabel,
            'label' => $hint->text ?? $typeLabel,
            'kind' => $hint->kind,
            'icon' => $blockType::getIcon(),
            'missing' => false,
        ];
    }

    /**
     * Draft order, deleted pruned. `getSections()`/`getBlocks()` are ordered
     * by the *published* position, so an unpublished reorder needs this sort.
     *
     * @template T of Section|Column|Block
     *
     * @param array<int, T> $nodes
     *
     * @return list<T>
     */
    private function ordered(array $nodes): array
    {
        $nodes = array_values(array_filter($nodes, static fn (object $n): bool => !$n->isDeleted()));
        usort($nodes, static fn (object $a, object $b): int => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $nodes;
    }

    private function labelOf(string|TranslatableInterface $label): string
    {
        return $label instanceof TranslatableInterface
            ? $label->trans($this->translator)
            : $this->translator->trans($label);
    }
}
