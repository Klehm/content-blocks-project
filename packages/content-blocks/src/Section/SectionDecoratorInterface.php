<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Extension point for a section's outer markup. Autoconfigured; decorations
 * merge in service order, the built-in one first.
 *
 * @see docs/internals/rendering.md#section-decorators-emit-variables
 */
interface SectionDecoratorInterface
{
    /** @param array<string, mixed> $settings effective settings */
    public function decorate(array $settings, Section $section): SectionDecoration;
}
