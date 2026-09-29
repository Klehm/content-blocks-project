<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Snapshot\SnapshotExtensionInterface;

/**
 * Captures each block's `title` and column's preset under its ref, and
 * records what restore() was handed, so a test reads the ref mapping.
 */
final class RecordingSnapshotExtension implements SnapshotExtensionInterface
{
    /**
     * @var list<array{
     *     blocks: array<string, Block>,
     *     columns: array<string, Column>,
     *     fragment: array<string, mixed>,
     * }>
     */
    public array $restored = [];

    public function key(): string
    {
        return 'acme/recording';
    }

    public function capture(array $blocks, array $columns): array
    {
        $out = [];
        foreach ($blocks as $ref => $block) {
            $out['blocks'][$ref] = ($block->getDraftData() ?? [])['title'] ?? null;
        }
        foreach ($columns as $ref => $column) {
            $out['columns'][$ref] = $column->getPreset();
        }

        return $out;
    }

    public function restore(array $blocks, array $columns, array $fragment): void
    {
        $this->restored[] = ['blocks' => $blocks, 'columns' => $columns, 'fragment' => $fragment];
    }
}
