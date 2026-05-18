<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Core defaults for the styling sub-form (added by SectionSettingsType).
 *
 * Why a default for backgroundColor: HTML5 `<input type="color">` has no
 * "empty" state — it always carries a value, defaulting to `#000000` on
 * an unset field. Without a sane initial value the form would persist
 * pure black the moment a user clicks Save without touching the color
 * picker, even though they never meant to set a background.
 *
 * We pre-populate the form with `#ffffff` and rely on
 * {@see SectionSettingsDefaults::withoutDefaults()} to strip the value
 * out again before it reaches the decorator pipeline — so saving a
 * section with the default white background produces no inline style at
 * all. Users who explicitly want a white background pay the same price:
 * white is treated as "no override". This is a known compromise.
 *
 * Hosts can override by registering their own provider that returns a
 * different default for `styling.backgroundColor`.
 */
final class CoreStylingDefaults implements SectionSettingsDefaultsProviderInterface
{
    public function getDefaults(): array
    {
        return [
            'styling' => [
                'backgroundColor' => '#ffffff',
            ],
        ];
    }
}
