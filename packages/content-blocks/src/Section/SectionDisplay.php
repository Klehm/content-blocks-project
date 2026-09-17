<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * How a section shows its columns, per viewport: side by side, as a slider,
 * as tabs or as accordion panels. `display` is the desktop value.
 *
 * @see docs/internals/rendering.md#display-per-viewport
 */
final class SectionDisplay
{
    public const SETTING = 'display';
    public const SETTING_TABLET = 'displayTablet';
    public const SETTING_MOBILE = 'displayMobile';

    public const GRID = 'grid';
    public const SLIDER = 'slider';
    public const TABS = 'tabs';
    public const ACCORDION = 'accordion';
    /** The sidebar's "same as above" choice; never stored. */
    public const INHERIT = 'inherit';

    /** Accordion settings: one panel open at a time, and none at first. */
    public const ACCORDION_SINGLE = 'accordionSingle';
    public const ACCORDION_COLLAPSED = 'accordionCollapsed';

    /** Slider settings. */
    public const SLIDER_PER_VIEW = 'sliderPerView';
    public const SLIDER_CONTROLS = 'sliderControls';
    public const SLIDER_AUTOPLAY = 'sliderAutoplay';
    public const SLIDER_LOOP = 'sliderLoop';
    public const CONTROLS = ['both', 'arrows', 'dots', 'none'];
    public const MAX_PER_VIEW = 6;
    public const MAX_AUTOPLAY = 60;

    public const VIEWPORTS = ['desktop', 'tablet', 'mobile'];

    /** The only values a render acts on; anything else reads as a grid. */
    public const ALL = [self::GRID, self::SLIDER, self::TABS, self::ACCORDION];

    /** What a narrower viewport may turn into, keyed by the one above. */
    private const COMPACTER = [
        self::GRID => [self::GRID, self::SLIDER, self::ACCORDION],
        self::SLIDER => [self::GRID, self::SLIDER, self::ACCORDION],
        self::TABS => [self::TABS, self::ACCORDION],
        self::ACCORDION => [self::ACCORDION],
    ];

    /**
     * The desktop value.
     *
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): string
    {
        $value = $settings[self::SETTING] ?? null;

        return \in_array($value, self::ALL, true) ? $value : self::GRID;
    }

    /**
     * Each viewport resolved: inherited when absent, and clamped to what the
     * viewport above allows.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{desktop: string, tablet: string, mobile: string}
     */
    public static function resolve(array $settings): array
    {
        $desktop = self::fromSettings($settings);
        $tablet = self::below($desktop, $settings[self::SETTING_TABLET] ?? null);

        return [
            'desktop' => $desktop,
            'tablet' => $tablet,
            'mobile' => self::below($tablet, $settings[self::SETTING_MOBILE] ?? null),
        ];
    }

    /** @return list<string> */
    public static function allowedBelow(string $above): array
    {
        return self::COMPACTER[$above] ?? self::COMPACTER[self::GRID];
    }

    /**
     * Drops the tablet/mobile keys that resolve to the viewport above, so
     * what is stored is only what differs.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        $resolved = self::resolve($settings);
        foreach ([self::SETTING_TABLET => ['desktop', 'tablet'], self::SETTING_MOBILE => ['tablet', 'mobile']] as $key => [$above, $own]) {
            if ($resolved[$own] === $resolved[$above]) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $resolved[$own];
            }
        }

        return $settings;
    }

    /**
     * @param array{desktop: string, tablet: string, mobile: string} $displays
     */
    public static function isUniform(array $displays): bool
    {
        return $displays['desktop'] === $displays['tablet'] && $displays['tablet'] === $displays['mobile'];
    }

    /** Whether the display prints the column labels as headings. */
    public static function showsTitles(string $display): bool
    {
        return $display === self::TABS || $display === self::ACCORDION;
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

    /**
     * Slides shown at once per viewport, inherited downwards, 1 by default.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{desktop: int, tablet: int, mobile: int}
     */
    public static function perView(array $settings): array
    {
        $raw = $settings[self::SLIDER_PER_VIEW] ?? null;
        $raw = \is_array($raw) ? $raw : [];
        $out = [];
        $previous = 1;
        foreach (self::VIEWPORTS as $viewport) {
            $value = $raw[$viewport] ?? null;
            if (\is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }
            $previous = \is_int($value) && $value >= 1 ? min($value, self::MAX_PER_VIEW) : $previous;
            $out[$viewport] = $previous;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array{controls: string, autoplay: int, loop: bool}
     */
    public static function sliderOptions(array $settings): array
    {
        $controls = $settings[self::SLIDER_CONTROLS] ?? null;
        $autoplay = $settings[self::SLIDER_AUTOPLAY] ?? 0;
        if (\is_string($autoplay) && ctype_digit($autoplay)) {
            $autoplay = (int) $autoplay;
        }

        return [
            'controls' => \in_array($controls, self::CONTROLS, true) ? $controls : 'both',
            'autoplay' => \is_int($autoplay) ? max(0, min($autoplay, self::MAX_AUTOPLAY)) : 0,
            'loop' => ($settings[self::SLIDER_LOOP] ?? false) === true,
        ];
    }

    private static function below(string $above, mixed $value): string
    {
        return \is_string($value) && \in_array($value, self::allowedBelow($above), true)
            ? $value
            : $above;
    }
}
