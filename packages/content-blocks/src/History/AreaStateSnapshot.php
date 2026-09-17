<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\ViewportOrder;

/**
 * The draft fields a builder action can move, read once for a chosen scope.
 * Two reads diffed give an entry its inverse.
 *
 * @see docs/internals/history.md#the-delta-is-a-targeted-read
 *
 * @internal
 */
final class AreaStateSnapshot
{
    public const T_SECTION = 'section';
    public const T_COLUMN = 'column';
    public const T_BLOCK = 'block';

    /** @param array<string, array<string, mixed>> $entries keyed "block:42" */
    private function __construct(private readonly array $entries)
    {
    }

    /**
     * Where everything sits, and nothing of what it says: a create is undone
     * by the `deleted` flag, so no payload is ever at risk.
     *
     * @see docs/internals/history.md#why-structure-carries-no-payload
     */
    public static function structure(ContentArea $area): self
    {
        $entries = [];

        foreach ($area->getSections() as $section) {
            $sectionId = $section->getId();
            if ($sectionId === null) {
                continue;
            }
            $entries[self::T_SECTION . ':' . $sectionId] = [
                'deleted' => $section->isDeleted(),
                'previewPosition' => $section->getPreviewPosition(),
                'layout' => $section->getLayout(),
            ];

            foreach ($section->getColumns() as $column) {
                $columnId = $column->getId();
                if ($columnId === null) {
                    continue;
                }
                $entries[self::T_COLUMN . ':' . $columnId] = [
                    'deleted' => $column->isDeleted(),
                    'previewPosition' => $column->getPreviewPosition(),
                    'preset' => $column->getPreset(),
                ];

                foreach ($column->getBlocks() as $block) {
                    $blockId = $block->getId();
                    if ($blockId === null) {
                        continue;
                    }
                    $entries[self::T_BLOCK . ':' . $blockId] = [
                        'deleted' => $block->isDeleted(),
                        'previewPosition' => $block->getPreviewPosition(),
                        'columnId' => $columnId,
                        'publishedColumnId' => $block->getPublishedColumnId(),
                    ];
                }
            }
        }

        return new self($entries);
    }

    /**
     * The `_order` ranks alone, so undoing a viewport reorder never rewrites
     * a payload edited since.
     *
     * @see docs/internals/rendering.md#order-per-viewport
     */
    public static function viewportOrder(ContentArea $area): self
    {
        $entries = [];
        foreach ($area->getSections() as $section) {
            if ($section->getId() !== null) {
                $entries[self::T_SECTION . ':' . $section->getId()] = [
                    'order' => self::orderOf($section),
                ];
            }
            foreach ($section->getColumns() as $column) {
                foreach ($column->getBlocks() as $block) {
                    if ($block->getId() !== null) {
                        $entries[self::T_BLOCK . ':' . $block->getId()] = [
                            'order' => self::orderOf($block),
                        ];
                    }
                }
            }
        }

        return new self($entries);
    }

    /** @return array<string, int>|null null when no rank is set */
    public static function orderOf(Section|Block $entity): ?array
    {
        $ranks = ViewportOrder::ranks($entity instanceof Section
            ? $entity->getEffectiveSettings(preferDraft: true)
            : ($entity->getDraftData() ?? $entity->getPublishedData()));

        return $ranks === [] ? null : $ranks;
    }

    public static function blockData(Block $block): self
    {
        $id = $block->getId();

        return new self($id === null ? [] : [
            self::T_BLOCK . ':' . $id => ['data' => $block->getDraftData()],
        ]);
    }

    public static function columnSettings(Column $column): self
    {
        $id = $column->getId();

        return new self($id === null ? [] : [
            self::T_COLUMN . ':' . $id => ['settings' => $column->getDraftSettings()],
        ]);
    }

    public static function sectionSettings(Section $section): self
    {
        $id = $section->getId();

        return new self($id === null ? [] : [
            self::T_SECTION . ':' . $id => ['settings' => $section->getDraftSettings()],
        ]);
    }

    /**
     * A key only `$after` has is a creation, inverted by the `deleted` flag. A
     * key only *this* has means a row left the draft, which nothing can undo.
     *
     * @see docs/internals/history.md#the-delta-is-a-targeted-read
     */
    public function diff(self $after): ActionDelta
    {
        foreach ($this->entries as $key => $_) {
            if (!isset($after->entries[$key])) {
                return ActionDelta::incomplete();
            }
        }

        $undo = [];
        $redo = [];

        foreach ($after->entries as $key => $now) {
            $was = $this->entries[$key] ?? null;

            if ($was === null) {
                $undo[] = self::op($key, ['deleted' => true]);
                $redo[] = self::op($key, $now);

                continue;
            }

            $changed = [];
            foreach ($now as $field => $value) {
                if (!self::same($was[$field] ?? null, $value)) {
                    $changed[$field] = $value;
                }
            }
            if ($changed === []) {
                continue;
            }

            $undo[] = self::op($key, array_intersect_key($was, $changed));
            $redo[] = self::op($key, $changed);
        }

        return new ActionDelta($undo, $redo, true);
    }

    /**
     * @param array<string, mixed> $set
     *
     * @return array{t: string, id: int, set: array<string, mixed>}
     */
    private static function op(string $key, array $set): array
    {
        [$type, $id] = explode(':', $key, 2);

        return ['t' => $type, 'id' => (int) $id, 'set' => $set];
    }

    /** Arrays compare by content; `==` keeps list order significant. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b)) {
            return $a == $b;
        }

        return $a === $b;
    }
}
