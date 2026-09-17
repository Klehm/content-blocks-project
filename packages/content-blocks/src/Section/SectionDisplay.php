<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * How a section shows its columns: side by side, as tabs, or as accordion
 * panels. Stored as the `display` section setting.
 *
 * @see docs/internals/rendering.md#columns-and-tabs
 */
final class SectionDisplay
{
    public const SETTING = 'display';
    public const GRID = 'grid';
    public const TABS = 'tabs';
    public const ACCORDION = 'accordion';

    /** Accordion settings: one panel open at a time, and none at first. */
    public const ACCORDION_SINGLE = 'accordionSingle';
    public const ACCORDION_COLLAPSED = 'accordionCollapsed';

    /** The only values a render acts on; anything else reads as a grid. */
    public const ALL = [self::GRID, self::TABS, self::ACCORDION];

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): string
    {
        $value = $settings[self::SETTING] ?? null;

        return \in_array($value, self::ALL, true) ? $value : self::GRID;
    }

    /** Whether the display prints each column's label as a heading. */
    public static function showsTitles(string $display): bool
    {
        return $display !== self::GRID;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array{single: bool, collapsed: bool}
     */
    public static function accordionOptions(array $settings): array
    {
        return [
            'single' => ($settings[self::ACCORDION_SINGLE] ?? false) === true,
            'collapsed' => ($settings[self::ACCORDION_COLLAPSED] ?? false) === true,
        ];
    }
}
