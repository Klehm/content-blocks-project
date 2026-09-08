<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Reads the `styling` sub-form and emits the CSS custom properties and classes
 * that `styling.css` maps to real declarations, media queries included.
 *
 * @see docs/internals/rendering.md#section-decorators-emit-variables
 */
final class StylingSectionDecorator implements SectionDecoratorInterface
{
    private const SIDE_SHORT = ['top' => 't', 'right' => 'r', 'bottom' => 'b', 'left' => 'l'];
    // Data keys are spelled out; the emitted CSS var names stay terse (the
    // stylesheet reads --cb-*-d/t/m-*). This map bridges the two.
    private const VIEWPORT_SHORT = ['desktop' => 'd', 'tablet' => 't', 'mobile' => 'm'];
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

        // Section vars are namespaced `--cb-s-*` so they do not inherit into
        // descendant blocks, which read `--cb-b-*`.
        foreach (['padding' => 's-pad', 'margin' => 's-mar'] as $key => $short) {
            $responsive = $styling[$key] ?? null;
            if (!\is_array($responsive)) {
                continue;
            }
            foreach (self::VIEWPORT_SHORT as $viewport => $vpShort) {
                $box = $responsive[$viewport] ?? null;
                if (!\is_array($box)) {
                    continue;
                }
                foreach (self::SIDE_SHORT as $side => $sideShort) {
                    $value = $box[$side] ?? null;
                    if (\is_int($value)) {
                        $vars["--cb-{$short}-{$vpShort}-{$sideShort}"] = $value . 'px';
                    }
                }
            }
        }

        // Set on the section, inherited by the inner .cb-row.
        $gap = $styling['gap'] ?? null;
        if (\is_array($gap)) {
            foreach (self::VIEWPORT_SHORT as $viewport => $vpShort) {
                $value = $gap[$viewport] ?? null;
                if (\is_int($value) && $value >= 0) {
                    $vars["--cb-gap-{$vpShort}"] = $value . 'px';
                }
            }
        }

        // Background color.
        $bg = $styling['backgroundColor'] ?? null;
        if (\is_string($bg) && $bg !== '') {
            $vars['--cb-s-bg'] = $bg;
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
