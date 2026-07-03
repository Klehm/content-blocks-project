<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Core defaults for the styling sub-form (added by SectionSettingsType).
 *
 * `backgroundColor` defaults to '' (no background): the palette color
 * field ({@see \ContentBlocks\Form\Type\PaletteColorType}) has a real
 * "None" state, so — unlike the raw `<input type="color">` it replaced —
 * an untouched form no longer needs a sacrificial `#ffffff` default to
 * avoid persisting black. Sections start transparent, and picking White
 * from the palette applies a real `#ffffff`.
 *
 * Upgrade note: settings saved before this change may carry
 * `styling.backgroundColor = '#ffffff'` that used to be stripped as
 * default-equal and now renders as an actual white background.
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
                'backgroundColor' => '',
            ],
        ];
    }
}
