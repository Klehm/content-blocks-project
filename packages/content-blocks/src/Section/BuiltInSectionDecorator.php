<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Maps the built-in section settings — `classes`, `widthMode`, `maxWidth`,
 * `styleName` — to a {@see SectionDecoration}. Always registered first.
 *
 * Settings shape:
 *  - classes:    string  free-form whitespace-separated CSS classes
 *  - widthMode:  'full'|'centered'  default 'full'
 *  - maxWidth:   int|null           when widthMode==='centered', applies max-width:Npx + auto margins
 *  - styleName:  string|null        a name registered via SectionStyleRegistry
 */
final class BuiltInSectionDecorator implements SectionDecoratorInterface
{
    public function __construct(
        private readonly SectionStyleRegistry $styleRegistry,
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

        $widthMode = $settings['widthMode'] ?? 'full';
        if ($widthMode === 'centered') {
            $classes[] = 'cb-section--centered';
            $maxWidth = $settings['maxWidth'] ?? null;
            if (\is_int($maxWidth) && $maxWidth > 0) {
                $styles['max-width'] = $maxWidth . 'px';
                $styles['margin-left'] = 'auto';
                $styles['margin-right'] = 'auto';
            }
        }

        $styleName = $settings['styleName'] ?? null;
        if (\is_string($styleName) && $styleName !== '') {
            $style = $this->styleRegistry->get($styleName);
            if ($style !== null) {
                $classes[] = $style->cssClass;
            }
        }

        return new SectionDecoration(classes: $classes, inlineStyles: $styles);
    }
}
