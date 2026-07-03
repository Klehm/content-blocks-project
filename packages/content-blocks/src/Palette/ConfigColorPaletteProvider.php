<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * Palette provider fed by the bundle's semantic config
 * (`content_blocks.palette`). Registered unconditionally; an empty config
 * simply contributes no colors.
 */
final class ConfigColorPaletteProvider implements ColorPaletteProviderInterface
{
    /**
     * @param list<array{label: string, color: string}> $palette
     */
    public function __construct(
        private readonly array $palette = [],
    ) {
    }

    public function getColors(): iterable
    {
        foreach ($this->palette as $entry) {
            yield new PaletteColor($entry['label'], $entry['color']);
        }
    }
}
