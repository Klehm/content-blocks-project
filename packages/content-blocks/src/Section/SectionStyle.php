<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * A named style preset that can be assigned to a Section. Devs register
 * styles via {@see SectionStyleProviderInterface} (or the bundle's
 * `content_blocks.styles` config); the editor exposes them as a dropdown
 * in the section settings sidebar.
 *
 * A preset carries two things:
 *  - `cssClass`  — attached to the rendered `<section>` (host-defined look)
 *  - `settings`  — optional section settings values (same shape as
 *    draft_settings, e.g. `['styling' => ['padding' => ...]]`) applied
 *    underneath the section's own settings at render time: the preset is
 *    the base, the user's explicit values win key-by-key.
 *
 * Either half is optional — a class-only preset (pure CSS) and a
 * settings-only preset (pure values) are both valid.
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
