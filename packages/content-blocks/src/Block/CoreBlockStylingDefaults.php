<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Core defaults for a block's `styling` sub-form. `backgroundColor` is `''`,
 * so blocks start transparent.
 *
 * @see docs/internals/forms.md#the-transparent-background-default
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
