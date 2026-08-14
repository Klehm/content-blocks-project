<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Icon;

/**
 * Contributes icons to the `icon` block's picker and to `cb_kit_icon()`.
 *
 * The kit ships {@see IconSet}, a fixed list of 23 glyphs, and that list used
 * to be the whole story: `content_blocks_kit.blocks.icon.choices` could narrow
 * it and nothing could widen it. Naming an icon the kit does not have produced
 * an *empty* block — `cb_kit_icon()` returned nothing and the view rendered
 * none of its markup — which is a worse failure than any other choice field in
 * the kit, and the only one config could reach.
 *
 * So icons are added here rather than through `choices`: a provider supplies
 * the glyph *and* its name in one place, which is the only way the picker and
 * the page can agree. `choices` keeps its usual meaning on top — restrict or
 * reorder what the registry ended up with.
 *
 * Implementations are autoconfigured; declaring the service is enough.
 *
 * ```php
 * final class BrandIcons implements IconProviderInterface
 * {
 *     public function icons(): array
 *     {
 *         // Inner SVG markup only, drawn on a 24×24 viewBox. The wrapper
 *         // <svg> — sizing, currentColor, stroke width — is the kit's.
 *         return ['brand-logo' => '<path d="M4 4h16v16H4z"/>'];
 *     }
 * }
 * ```
 *
 * A provider returning a name the kit already ships **replaces** that glyph,
 * which is how a host swaps the shipped look without touching the block.
 */
interface IconProviderInterface
{
    /**
     * @return array<string, string> icon name => inner SVG markup (no wrapper `<svg>`)
     */
    public function icons(): array;
}
