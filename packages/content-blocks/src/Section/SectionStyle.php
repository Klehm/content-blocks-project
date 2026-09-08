<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * A named style preset assignable to a Section: a `cssClass`, a `settings` map
 * in the `draft_settings` shape, or both. Either half alone is valid.
 *
 * @see docs/internals/rendering.md#style-presets-as-a-base-layer
 */
final class SectionStyle
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $cssClass = '',
        public readonly array $settings = [],
    ) {
    }
}
