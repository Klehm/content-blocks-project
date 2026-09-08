<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Icon;

/**
 * Contributes icons to the picker and to `cb_kit_icon()`. Autoconfigured; a
 * name the kit already ships is **replaced**, not ignored.
 *
 * @see docs/internals/kit.md#icons-are-added-not-filtered
 */
interface IconProviderInterface
{
    /**
     * @return array<string, string> name => inner SVG, no wrapper `<svg>`
     */
    public function icons(): array;
}
