<?php

declare(strict_types=1);

namespace App\ContentBlocks\Palette;

use ContentBlocks\Palette\ColorPaletteProviderInterface;
use ContentBlocks\Palette\PaletteColor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stands for a colour an admin edits in a back office: pages store the
 * variable, the builder previews the current hex. Autoconfigured.
 */
final class BackOfficePaletteProvider implements ColorPaletteProviderInterface
{
    public function __construct(
        #[Autowire('%app.theme.brand%')]
        private readonly string $brand,
    ) {
    }

    public function getColors(): iterable
    {
        yield new PaletteColor('Brand (back office)', 'var(--demo-brand)', $this->brand);
    }
}
