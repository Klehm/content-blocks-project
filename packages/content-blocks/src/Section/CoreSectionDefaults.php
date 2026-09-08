<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Core defaults for settings living at the root of the array; mirror of
 * {@see CoreStylingDefaults}, which covers the `styling` sub-form.
 *
 * @see docs/internals/rendering.md#defaults-and-why-they-are-stripped
 */
final class CoreSectionDefaults implements SectionSettingsDefaultsProviderInterface
{
    public function __construct(
        private readonly int $defaultMaxWidth = 1320,
        private readonly string $defaultWidthMode = 'full',
    ) {
    }

    public function getDefaults(): array
    {
        return [
            'widthMode' => $this->defaultWidthMode,
            'maxWidth' => $this->defaultMaxWidth,
        ];
    }
}
