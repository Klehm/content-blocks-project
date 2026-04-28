<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * A named CSS class preset that can be assigned to a Section. Devs register
 * styles via {@see SectionStyleProviderInterface}; the editor exposes them
 * as a dropdown in the section settings sidebar.
 */
final class SectionStyle
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $cssClass,
    ) {
    }
}
