<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Registers section style presets. Autoconfigured; the registry merges
 * providers by `name`, later ones winning.
 *
 * @see docs/internals/rendering.md#style-presets-as-a-base-layer
 */
interface SectionStyleProviderInterface
{
    /** @return list<SectionStyle> */
    public function getStyles(): array;
}
