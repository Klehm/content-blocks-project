<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Layout and preset read back from an import, a template or a paste: kept
 * only when they name something this install renders.
 *
 * @see docs/guide/security.md#restored-structure
 */
final class RestoredStructure
{
    private const PRESET = '/^col-(1[0-2]|[1-9])$/';

    public static function layout(mixed $layout, SectionLayoutRegistry $layouts): ?string
    {
        return \is_string($layout) && $layouts->get($layout) !== null ? $layout : null;
    }

    public static function preset(mixed $preset): ?string
    {
        return \is_string($preset) && preg_match(self::PRESET, $preset) === 1 ? $preset : null;
    }
}
