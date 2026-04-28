<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Extension point for the section render pipeline.
 *
 * Tag with `content_blocks.section_decorator` (or autoconfigure) and the
 * package's collection will call you for every section being rendered.
 * Return a {@see SectionDecoration} contributing classes, attributes or
 * inline styles based on the section's settings.
 *
 * Multiple decorators are merged in service-order; the built-in decorator
 * runs first so host extensions can react to or override its output.
 *
 * Example — set a background color from a custom setting:
 *
 *     final class BgColorDecorator implements SectionDecoratorInterface {
 *         public function decorate(array $settings, Section $section): SectionDecoration {
 *             $color = $settings['backgroundColor'] ?? null;
 *             if (!is_string($color) || $color === '') return new SectionDecoration();
 *             return new SectionDecoration(inlineStyles: ['background-color' => $color]);
 *         }
 *     }
 */
interface SectionDecoratorInterface
{
    /** @param array<string, mixed> $settings effective settings for the section being rendered */
    public function decorate(array $settings, Section $section): SectionDecoration;
}
