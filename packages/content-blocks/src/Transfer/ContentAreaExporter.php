<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Default {@see ContentAreaExporterInterface} — see it for the payload shape.
 * Assets are found through {@see AssetResolverInterface}, deduplicated by hash.
 */
final class ContentAreaExporter implements ContentAreaExporterInterface
{
    private readonly AssetReferenceCollector $collector;

    /**
     * @param iterable<ContentAreaTransferExtensionInterface> $extensions
     */
    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
        private readonly int $contentVersion = 1,
        ?AssetReferenceCollector $collector = null,
        private readonly iterable $extensions = [],
    ) {
        $this->collector = $collector ?? new AssetReferenceCollector($assetResolver);
    }

    /**
     * @return array<string, mixed>
     */
    public function export(ContentArea $area): array
    {
        $assets = new AssetTokenizer($this->collector, $this->assetResolver);
        $sections = $this->collectByPreviewPosition(
            $area->getSections()->toArray(),
        );

        /** @var array<string, Block> $blocks */
        $blocks = [];
        $exportedSections = [];
        foreach ($sections as $i => $section) {
            $exportedSections[] = $this->exportSection($section, 's' . $i, $assets, $blocks);
        }

        $extensions = $this->exportExtensions($area, $blocks, $assets);

        $payload = [
            'format' => self::FORMAT,
            // Informative only: a content version belongs to the app that
            // issued it, so the importer on the other side ignores it.
            'contentVersion' => $this->contentVersion,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'contentArea' => [
                'sections' => $exportedSections,
            ],
            // Read after the extensions ran: one of them may carry a file no
            // block's data mentions.
            'assets' => $assets->assets(),
        ];

        if ($extensions !== []) {
            $payload['extensions'] = $extensions;
        }

        return $payload;
    }

    /**
     * @param array<string, Block> $blocks
     *
     * @return array<string, array<string, mixed>>
     */
    private function exportExtensions(ContentArea $area, array $blocks, AssetTokenizer $assets): array
    {
        $out = [];

        foreach ($this->extensions as $extension) {
            $fragment = $extension->export($area, $blocks, $assets);

            // An empty fragment writes no key, so an install whose satellites
            // hold nothing exports exactly what it exported before them.
            if ($fragment !== []) {
                $out[$extension->key()] = $fragment;
            }
        }

        return $out;
    }

    /**
     * @param array<string, Block> $blocks
     *
     * @return array<string, mixed>
     */
    private function exportSection(Section $section, string $ref, AssetTokenizer $assets, array &$blocks): array
    {
        $settings = $section->getDraftSettings() ?? $section->getPublishedSettings();
        $columns = [];
        foreach ($this->collectByPreviewPosition($section->getColumns()->toArray()) as $i => $column) {
            $columns[] = $this->exportColumn($column, $ref . '.c' . $i, $assets, $blocks);
        }

        return [
            'layout' => $section->getLayout(),
            'settings' => $settings !== null && $settings !== []
                ? $assets->tokenize($settings)
                : null,
            'columns' => $columns,
        ];
    }

    /**
     * @param array<string, Block> $blocks
     *
     * @return array<string, mixed>
     */
    private function exportColumn(Column $column, string $ref, AssetTokenizer $assets, array &$blocks): array
    {
        $exported = [];
        foreach ($this->collectByPreviewPosition($column->getBlocks()->toArray()) as $i => $block) {
            $blockRef = $ref . '.b' . $i;
            $blocks[$blockRef] = $block;
            $exported[] = $this->exportBlock($block, $blockRef, $assets);
        }

        return [
            'preset' => $column->getPreset(),
            'blocks' => $exported,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportBlock(Block $block, string $ref, AssetTokenizer $assets): array
    {
        $data = $block->getDraftData() ?? $block->getPublishedData() ?? [];

        return [
            // Positional and stable: what an extension fragment addresses.
            // See docs/internals/transfer.md#what-is-stored-beside-a-block
            'ref' => $ref,
            'type' => $block->getType(),
            'data' => $assets->tokenize($data),
        ];
    }

    /**
     * Filters out soft-deleted entries and sorts by previewPosition — same
     * convention as the replace flow and the rendering pipeline.
     *
     * @template T of Section|Column|Block
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
