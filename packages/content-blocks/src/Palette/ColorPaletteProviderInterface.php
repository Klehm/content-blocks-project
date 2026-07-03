<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * Provides the project color palette surfaced by {@see \ContentBlocks\Form\Type\PaletteColorType}
 * (a dropdown of named colors plus a "Custom…" free picker).
 *
 * Implementations are auto-tagged `content_blocks.color_palette_provider`
 * (see ContentBlocksBundle::build()), so a host only needs `autoconfigure: true`.
 * The simplest setup needs no PHP at all — declare the palette in the bundle
 * config instead:
 *
 *     content_blocks:
 *         palette:
 *             - { label: 'Primary', color: '#eb0540' }
 *             - { label: 'Dark',    color: '#252525' }
 */
interface ColorPaletteProviderInterface
{
    /**
     * @return iterable<PaletteColor>
     */
    public function getColors(): iterable;
}
