<?php

declare(strict_types=1);

namespace ContentBlocks\Service;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Serializes a ContentArea (sections, columns, blocks, settings, data) into
 * a self-contained array suitable for JSON encoding. Asset references inside
 * block data are detected via AssetResolverInterface, read from storage, and
 * embedded as base64; the original path is replaced with an `asset://{hash}`
 * token. Identical binaries are deduplicated by sha256 hash.
 *
 * Draft state takes precedence over published state (mirrors SectionCloner
 * and the rest of the builder's lifecycle).
 */
final class ContentAreaExporter
{
    public const FORMAT = 'content-blocks/v1';

    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(ContentArea $area): array
    {
        $assets = [];
        $sections = $this->collectByPreviewPosition(
            $area->getSections()->toArray(),
        );

        $exportedSections = [];
        foreach ($sections as $section) {
            $exportedSections[] = $this->exportSection($section, $assets);
        }

        return [
            'format' => self::FORMAT,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'contentArea' => [
                'sections' => $exportedSections,
            ],
            'assets' => $assets,
        ];
    }

    /**
     * @param array<string, mixed> $assets
     *
     * @return array<string, mixed>
     */
    private function exportSection(Section $section, array &$assets): array
    {
        $settings = $section->getDraftSettings() ?? $section->getPublishedSettings();
        $columns = [];
        foreach ($this->collectByPreviewPosition($section->getColumns()->toArray()) as $column) {
            $columns[] = $this->exportColumn($column, $assets);
        }

        return [
            'layout' => $section->getLayout(),
            'settings' => $settings !== null && $settings !== []
                ? $this->walkAssets($settings, $assets)
                : null,
            'columns' => $columns,
        ];
    }

    /**
     * @param array<string, mixed> $assets
     *
     * @return array<string, mixed>
     */
    private function exportColumn(Column $column, array &$assets): array
    {
        $blocks = [];
        foreach ($this->collectByPreviewPosition($column->getBlocks()->toArray()) as $block) {
            $blocks[] = $this->exportBlock($block, $assets);
        }

        return [
            'preset' => $column->getPreset(),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $assets
     *
     * @return array<string, mixed>
     */
    private function exportBlock(Block $block, array &$assets): array
    {
        $data = $block->getDraftData() ?? $block->getPublishedData() ?? [];

        return [
            'type' => $block->getType(),
            'data' => $this->walkAssets($data, $assets),
        ];
    }

    /**
     * Recursively walks an array, replacing every string that the resolver
     * recognizes as a stored asset with an `asset://{hash}` token and
     * registering the binary under that hash in $assets.
     *
     * @param array<string, mixed> $assets
     */
    private function walkAssets(mixed $value, array &$assets): mixed
    {
        if (is_string($value) && $this->assetResolver->isAssetPath($value)) {
            $binary = $this->assetResolver->read($value);
            if ($binary === null) {
                // Missing on disk — keep the original path so the import
                // side at least sees a reference rather than silently
                // dropping the field.
                return $value;
            }
            $hash = hash('sha256', $binary);
            if (!isset($assets[$hash])) {
                $extension = pathinfo($value, PATHINFO_EXTENSION);
                $assets[$hash] = [
                    'mimeType' => $this->guessMime($binary),
                    'extension' => is_string($extension) && $extension !== '' ? $extension : 'bin',
                    'data' => base64_encode($binary),
                ];
            }

            return 'asset://' . $hash;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->walkAssets($v, $assets);
            }

            return $out;
        }

        return $value;
    }

    private function guessMime(string $binary): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
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
