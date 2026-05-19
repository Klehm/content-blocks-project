<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Core defaults for top-level section settings (added by
 * SectionSettingsType). Mirror of {@see CoreStylingDefaults} for the
 * `styling` sub-form, but covers fields living at the root of the
 * settings array.
 *
 * Why a default for `maxWidth`: when the user picks "centered" without
 * typing a number, we still want a sensible container width applied.
 * Pre-populating the form with 1320 means the input box always shows a
 * value the user can edit or replace, and `BuiltInSectionDecorator`
 * falls back to the same number when the saved settings carry no
 * explicit value — so a freshly-created centered section is never
 * uncapped.
 *
 * Overriding: hosts can either set the parameter
 * `content_blocks.section.default_max_width`, or register their own
 * {@see SectionSettingsDefaultsProviderInterface} returning a different
 * `maxWidth` value (later providers win on conflict).
 */
final class CoreSectionDefaults implements SectionSettingsDefaultsProviderInterface
{
    public function __construct(
        private readonly int $defaultMaxWidth = 1320,
    ) {
    }

    public function getDefaults(): array
    {
        return ['maxWidth' => $this->defaultMaxWidth];
    }
}
