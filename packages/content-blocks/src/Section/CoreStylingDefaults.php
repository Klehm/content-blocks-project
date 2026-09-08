<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Core defaults for the styling sub-form. `backgroundColor` is `''`, so
 * sections start transparent — an upgrade hazard, see the pointer.
 *
 * @see docs/internals/rendering.md#defaults-and-why-they-are-stripped
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
