<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * Default {@see SectionTemplateSerializerInterface} — see it for the contract.
 */
final class SectionTemplateSerializer implements SectionTemplateSerializerInterface
{
    public function serialize(Section $section): SectionTemplateSnapshot
    {
        $blockTypes = [];
        $settings = $section->getDraftSettings() ?? $section->getPublishedSettings();

        $columns = [];
        foreach ($this->collectByPreviewPosition($section->getColumns()->toArray()) as $column) {
            $columns[] = $this->serializeColumn($column, $blockTypes);
        }

        $payload = [
            'format' => self::FORMAT,
            'layout' => $section->getLayout(),
            'settings' => $settings !== null && $settings !== [] ? $settings : null,
            'columns' => $columns,
        ];

        return new SectionTemplateSnapshot($payload, array_values(array_unique($blockTypes)));
    }

    /**
     * @param list<string> $blockTypes accumulator, passed by reference
     *
     * @return array<string, mixed>
     */
    private function serializeColumn(Column $column, array &$blockTypes): array
    {
        $blocks = [];
        foreach ($this->collectByPreviewPosition($column->getBlocks()->toArray()) as $block) {
            $blocks[] = $this->serializeBlock($block, $blockTypes);
        }

        return [
            'preset' => $column->getPreset(),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param list<string> $blockTypes accumulator, passed by reference
     *
     * @return array<string, mixed>
     */
    private function serializeBlock(Block $block, array &$blockTypes): array
    {
        $blockTypes[] = $block->getType();

        return [
            'type' => $block->getType(),
            'data' => $block->getDraftData() ?? $block->getPublishedData() ?? [],
        ];
    }

    /**
     * Filters out soft-deleted entries and sorts by previewPosition — same
     * convention as the exporter, replace flow and rendering pipeline.
     *
     * @template T of Column|Block
     *
     * @param array<int, T> $items
     *
     * @return array<int, T>
     */
    private function collectByPreviewPosition(array $items): array
    {
        $alive = array_values(array_filter(
            $items,
            fn ($item) => !$item->isDeleted(),
        ));
        usort(
            $alive,
            fn ($a, $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition(),
        );

        return $alive;
    }
}
