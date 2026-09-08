<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Default values for the section settings form. Autoconfigured; providers
 * coexist and later ones win on key conflict.
 *
 * @see docs/internals/rendering.md#defaults-and-why-they-are-stripped
 */
interface SectionSettingsDefaultsProviderInterface
{
    /** @return array<string, mixed> */
    public function getDefaults(): array;
}
