<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Core defaults for the block's `styling` sub-form (added by
 * BlockFormType). Block-side mirror of
 * {@see \ContentBlocks\Section\CoreStylingDefaults}.
 *
 * Why a default for backgroundColor: HTML5 `<input type="color">` has
 * no "empty" state — it always carries a value, defaulting to `#000000`
 * on an unset field. Without a sane initial value the form would
 * persist pure black the moment a user clicks Save without touching
 * the color picker, even though they never meant to set a background.
 *
 * We pre-populate the form with `#ffffff` and rely on
 * {@see BlockDataDefaults::withoutDefaults()} to strip the value out
 * again before it reaches the decorator pipeline — so saving a block
 * with the default white background produces no inline style at all.
 * Users who explicitly want a white background pay the same price:
 * white is treated as "no override". This is a known compromise (and
 * is intentionally consistent with the section-side default).
 */
final class CoreBlockStylingDefaults implements BlockDataDefaultsProviderInterface
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
