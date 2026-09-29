<?php

declare(strict_types=1);

namespace ContentBlocks\Snapshot;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * Runs every {@see SnapshotExtensionInterface} over a section or a block, and
 * maps the positional refs back onto the copies a paste produced.
 *
 * @see docs/internals/section-templates.md#rows-kept-beside-a-block
 *
 * @internal
 */
final class SnapshotExtensions
{
    public const PAYLOAD_KEY = 'extensions';

    /** The ref of a block copied alone. */
    public const BLOCK_REF = 'b';

    /**
     * @param iterable<SnapshotExtensionInterface> $extensions
     */
    public function __construct(
        private readonly iterable $extensions = [],
        private readonly ?BlockTypeRegistry $registry = null,
    ) {
    }

    /**
     * Refs follow the default serializer's walk: live entities by preview
     * position, so `c1.b0` is the payload's `columns[1].blocks[0]`.
     *
     * @return array<string, array<string, mixed>> key => fragment
     */
    public function captureSection(Section $section): array
    {
        $blocks = [];
        $columns = [];

        foreach (self::alive($section->getColumns()->toArray()) as $j => $column) {
            $columns['c' . $j] = $column;

            foreach (self::alive($column->getBlocks()->toArray()) as $k => $block) {
                $blocks['c' . $j . '.b' . $k] = $block;
            }
        }

        return $this->capture($blocks, $columns);
    }

    /**
     * @return array<string, array<string, mixed>> key => fragment
     */
    public function captureBlock(Block $block): array
    {
        return $this->capture([self::BLOCK_REF => $block], []);
    }

    /**
     * $payload is the one the section was built from: a block whose type
     * is gone was skipped, so kept blocks are matched past it.
     *
     * @param array<string, mixed> $payload
     *
     * @return bool whether an extension was handed something to restore
     */
    public function restoreSection(Section $section, array $payload): bool
    {
        $fragments = self::fragmentsIn($payload);
        if ($fragments === []) {
            return false;
        }

        $blocks = [];
        $columns = [];
        $copies = self::alive($section->getColumns()->toArray());
        $rawColumns = \is_array($payload['columns'] ?? null) ? array_values($payload['columns']) : [];

        // The instantiator's own skips: non-array columns, unknown types.
        $j = 0;
        foreach ($rawColumns as $index => $rawColumn) {
            if (!\is_array($rawColumn)) {
                continue;
            }
            $column = $copies[$j++] ?? null;
            if ($column === null) {
                break;
            }
            $columns['c' . $index] = $column;

            $kept = self::alive($column->getBlocks()->toArray());
            $rawBlocks = \is_array($rawColumn['blocks'] ?? null) ? array_values($rawColumn['blocks']) : [];
            $k = 0;
            foreach ($rawBlocks as $blockIndex => $rawBlock) {
                if (!$this->wasKept($rawBlock)) {
                    continue;
                }
                $block = $kept[$k++] ?? null;
                if ($block === null) {
                    break;
                }
                $blocks['c' . $index . '.b' . $blockIndex] = $block;
            }
        }

        return $this->restore($fragments, $blocks, $columns);
    }

    /**
     * @param array<string, mixed> $payload the block snapshot pasted
     *
     * @return bool whether an extension was handed something to restore
     */
    public function restoreBlock(Block $block, array $payload): bool
    {
        return $this->restore(self::fragmentsIn($payload), [self::BLOCK_REF => $block], []);
    }

    /**
     * @param array<string, Block>  $blocks
     * @param array<string, Column> $columns
     *
     * @return array<string, array<string, mixed>>
     */
    private function capture(array $blocks, array $columns): array
    {
        $out = [];

        foreach ($this->extensions as $extension) {
            $fragment = $extension->capture($blocks, $columns);
            if ($fragment !== []) {
                $out[$extension->key()] = $fragment;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $fragments
     * @param array<string, Block>  $blocks
     * @param array<string, Column> $columns
     */
    private function restore(array $fragments, array $blocks, array $columns): bool
    {
        $ran = false;

        foreach ($fragments === [] ? [] : $this->extensions as $extension) {
            $fragment = $fragments[$extension->key()] ?? null;
            if (\is_array($fragment) && $fragment !== []) {
                $extension->restore($blocks, $columns, $fragment);
                $ran = true;
            }
        }

        return $ran;
    }

    private function wasKept(mixed $raw): bool
    {
        if (!\is_array($raw) || !\is_string($raw['type'] ?? null)) {
            return false;
        }

        return $this->registry?->has($raw['type']) ?? true;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function fragmentsIn(array $payload): array
    {
        $fragments = $payload[self::PAYLOAD_KEY] ?? null;

        return \is_array($fragments) ? $fragments : [];
    }

    /**
     * @template T of Column|Block
     *
     * @param array<int, T> $items
     *
     * @return list<T>
     */
    private static function alive(array $items): array
    {
        $alive = array_values(array_filter($items, static fn ($item) => !$item->isDeleted()));
        usort($alive, static fn ($a, $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $alive;
    }
}
