<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Core defaults for the block's `styling` sub-form (added by
 * BlockFormType). Block-side mirror of
 * {@see \ContentBlocks\Section\CoreStylingDefaults}.
 *
 * `backgroundColor` defaults to '' (no background): the palette color
 * field ({@see \ContentBlocks\Form\Type\PaletteColorType}) has a real
 * "None" state, so the historical `#ffffff` pre-fill — needed when the
 * field was a raw `<input type="color">` with no empty state — is gone.
 * Blocks start transparent, and picking White from the palette applies
 * a real `#ffffff`.
 */
final class CoreBlockStylingDefaults implements BlockDataDefaultsProviderInterface
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
