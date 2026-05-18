<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Reads the `styling` sub-form (added by SectionSettingsType) and emits
 * CSS custom properties + utility classes that the package's
 * `styling.css` stylesheet maps to actual properties — including per-
 * viewport overrides via `@media`.
 *
 * Inline style would not support media queries, so the indirection
 * (decorator emits vars, stylesheet emits responsive rules) is the only
 * clean path to responsive section styling. The stylesheet is shipped
 * with the package and loaded by render/content_area.html.twig.
 *
 * Settings shape (under `$settings['styling']`):
 *  - padding, margin: { d: BoxSpacing, t: BoxSpacing, m: BoxSpacing }
 *      where BoxSpacing = { top, right, bottom, left: int, linked: bool }
 *  - backgroundColor: string (#hex)
 *  - minHeight: { value: int, unit: 'px'|'vh' }
 *  - verticalAlign: 'start'|'center'|'end'
 */
final class StylingSectionDecorator implements SectionDecoratorInterface
{
    private const SIDE_SHORT = ['top' => 't', 'right' => 'r', 'bottom' => 'b', 'left' => 'l'];
    private const ALIGN_MAP = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
    ];

    public function decorate(array $settings, Section $section): SectionDecoration
    {
        $styling = $settings['styling'] ?? null;
        if (!\is_array($styling) || $styling === []) {
            return new SectionDecoration();
        }

        $vars = [];
        $classes = [];

        // Padding and margin: responsive (D/T/M) × 4 sides.
        foreach (['padding' => 'pad', 'margin' => 'mar'] as $key => $short) {
            $responsive = $styling[$key] ?? null;
            if (!\is_array($responsive)) {
                continue;
            }
            foreach (['d', 't', 'm'] as $viewport) {
                $box = $responsive[$viewport] ?? null;
                if (!\is_array($box)) {
                    continue;
                }
                foreach (self::SIDE_SHORT as $side => $sideShort) {
                    $value = $box[$side] ?? null;
                    if (\is_int($value)) {
                        $vars["--cb-{$short}-{$viewport}-{$sideShort}"] = $value . 'px';
                    }
                }
            }
        }

        // Background color.
        $bg = $styling['backgroundColor'] ?? null;
        if (\is_string($bg) && $bg !== '') {
            $vars['--cb-bg'] = $bg;
        }

        // Min height (value + unit).
        $minHeight = $styling['minHeight'] ?? null;
        if (\is_array($minHeight)) {
            $val = $minHeight['value'] ?? null;
            $unit = $minHeight['unit'] ?? 'px';
            if (\is_int($val) && $val > 0 && \in_array($unit, ['px', 'vh'], true)) {
                $vars['--cb-min-h'] = $val . $unit;
            }
        }

        // Vertical alignment (section is flex-column).
        $vAlign = $styling['verticalAlign'] ?? null;
        if (\is_string($vAlign) && isset(self::ALIGN_MAP[$vAlign])) {
            $vars['--cb-valign'] = self::ALIGN_MAP[$vAlign];
            $classes[] = 'cb-section--has-valign';
        }

        if ($vars === []) {
            return new SectionDecoration();
        }

        $classes[] = 'cb-section--styled';

        return new SectionDecoration(classes: $classes, inlineStyles: $vars);
    }
}
