<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Maps the built-in section settings — `classes`, `widthMode`, `maxWidth`,
 * `styleName` — to a {@see SectionDecoration}. Always registered first.
 *
 * @see docs/internals/rendering.md#section-decorators-emit-variables
 */
final class BuiltInSectionDecorator implements SectionDecoratorInterface
{
    public function __construct(
        private readonly SectionStyleRegistry $styleRegistry,
        private readonly int $defaultMaxWidth = 1320,
        private readonly string $defaultWidthMode = 'full',
    ) {
    }

    public function decorate(array $settings, Section $section): SectionDecoration
    {
        $classes = [];
        $styles = [];

        $custom = $settings['classes'] ?? '';
        if (\is_string($custom) && trim($custom) !== '') {
            foreach (preg_split('/\s+/', trim($custom)) ?: [] as $cls) {
                if ($cls !== '') {
                    $classes[] = $cls;
                }
            }
        }

        $widthMode = $settings['widthMode'] ?? $this->defaultWidthMode;
        if ($widthMode === 'centered') {
            $classes[] = 'cb-section--centered';
            // Missing key → fall back to the configured default. The
            // value 0 is preserved (user opting out of any cap).
            $maxWidth = \array_key_exists('maxWidth', $settings)
                ? $settings['maxWidth']
                : $this->defaultMaxWidth;
            if (\is_int($maxWidth) && $maxWidth > 0) {
                // Constrains the inner `.cb-row`, not the `<section>`, so the
                // background still spans the viewport. Read by layout.css.
                $styles['--cb-row-max-w'] = $maxWidth . 'px';
            }
        }

        $styleName = $settings['styleName'] ?? null;
        if (\is_string($styleName) && $styleName !== '') {
            $style = $this->styleRegistry->get($styleName);
            // Settings-only presets have no class to attach — their values
            // are merged into $settings upstream (BlockRenderer).
            if ($style !== null && $style->cssClass !== '') {
                $classes[] = $style->cssClass;
            }
        }

        return new SectionDecoration(classes: $classes, inlineStyles: $styles);
    }
}
