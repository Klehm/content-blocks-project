<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Implement and tag with `content_blocks.section_style_provider` (or use
 * autoconfigure) to register section style presets.
 *
 * Multiple providers can coexist; the registry merges their styles by
 * `name` (later ones win on conflict).
 *
 * Example:
 *
 *     final class AppStyles implements SectionStyleProviderInterface {
 *         public function getStyles(): array {
 *             return [
 *                 new SectionStyle('hero', 'Hero banner', 'app-section-hero'),
 *                 new SectionStyle('callout', 'Callout', 'app-section-callout'),
 *             ];
 *         }
 *     }
 */
interface SectionStyleProviderInterface
{
    /** @return list<SectionStyle> */
    public function getStyles(): array;
}
