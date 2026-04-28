<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Provides default values for the section settings form.
 *
 * The default-merging happens on form *load*: every key not already set in
 * the section's existing settings falls back to the merged defaults from
 * all registered providers. The form opens pre-populated, so widgets that
 * have no "empty" state (notably HTML5 color/range inputs) don't surprise
 * the user with browser defaults like #000000.
 *
 * Tag with `content_blocks.section_settings_defaults` (autoconfigured by
 * the bundle when implementing this interface). Multiple providers can
 * coexist; later providers override earlier ones on key conflict.
 *
 * Example — give the sandbox's backgroundColor a sensible white default:
 *
 *     final class SandboxDefaults implements SectionSettingsDefaultsProviderInterface {
 *         public function getDefaults(): array {
 *             return ['backgroundColor' => '#ffffff'];
 *         }
 *     }
 */
interface SectionSettingsDefaultsProviderInterface
{
    /** @return array<string, mixed> */
    public function getDefaults(): array;
}
