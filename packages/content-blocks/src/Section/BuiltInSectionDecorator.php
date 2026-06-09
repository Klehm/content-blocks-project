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
 *  - widthMode:  'full'|'centered'  defaults to $defaultWidthMode (host-configurable, ships 'full')
 *  - maxWidth:   int|null           when widthMode==='centered', emits `--cb-row-max-w:Npx` so the inner
 *                                   `.cb-row` is capped + centered while the section background stays
 *                                   full-width; missing or 0 falls back to $defaultMaxWidth so a centered
 *                                   section is never uncapped. Type 0 explicitly is treated as "no cap".
 *  - styleName:  string|null        a name registered via SectionStyleRegistry
 *
 * The default cap is bound to the parameter
 * `content_blocks.section.default_max_width` and shared with
 * {@see CoreSectionDefaults} so the form pre-fill and the rendered output
 * read the same number — a host overrides the parameter (or registers its
 * own defaults provider) and both surfaces update in lock-step.
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
                // Constrain the inner `.cb-row`, not the `<section>` itself,
                // so the section's background still spans the full viewport
                // width while its content stays centered. The var is read by
                // `.cb-section--centered > .cb-row` in layout.css; emitting it
                // as an inline custom property keeps the decorator writing to
                // the section element only (it has no handle on the row).
                $styles['--cb-row-max-w'] = $maxWidth . 'px';
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
