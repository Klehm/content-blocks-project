<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Controller\ColumnsController;

/**
 * Layout and preset read back from an import, a template or a paste: kept
 * only when they name something this install renders.
 *
 * @see docs/guide/security.md#restored-structure
 */
final class RestoredStructure
{
    private const PRESET = '/^col-(1[0-2]|[1-9])$/';

    /** Far past any real page; each block is replayed through its form. */
    public const MAX_SECTIONS = 1000;
    public const MAX_BLOCKS = 5000;

    public static function layout(mixed $layout, SectionLayoutRegistry $layouts): ?string
    {
        return \is_string($layout) && $layouts->get($layout) !== null ? $layout : null;
    }

    /**
     * Whether a payload's sections hold more than a page could: too many
     * sections, columns in one section, or blocks overall.
     *
     * @param array<mixed> $sections
     */
    public static function tooLarge(array $sections): bool
    {
        if (\count($sections) > self::MAX_SECTIONS) {
            return true;
        }
        $blocks = 0;
        foreach ($sections as $section) {
            $columns = \is_array($section) ? ($section['columns'] ?? null) : null;
            if (!\is_array($columns)) {
                continue;
            }
            if (\count($columns) > ColumnsController::MAX_COLUMNS) {
                return true;
            }
            foreach ($columns as $column) {
                $list = \is_array($column) ? ($column['blocks'] ?? null) : null;
                $blocks += \is_array($list) ? \count($list) : 0;
            }
        }

        return $blocks > self::MAX_BLOCKS;
    }

    public static function preset(mixed $preset): ?string
    {
        return \is_string($preset) && preg_match(self::PRESET, $preset) === 1 ? $preset : null;
    }
}
