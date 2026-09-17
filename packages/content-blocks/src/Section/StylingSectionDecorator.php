<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Palette\ColorTone;

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
    private const BACKGROUND_SIZES = ['cover' => true, 'contain' => true];
    private const BACKGROUND_POSITIONS = ['center' => true, 'top' => true, 'bottom' => true, 'left' => true, 'right' => true];
    /** Asked of the image resolver: a section spans the viewport. */
    private const BACKGROUND_WIDTH = 1920;
    /** Veil opacity, in percent, from which it decides the tone class. */
    private const TONE_VEIL_OPACITY = 40;
    private const ALIGN_MAP = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
    ];

    public function __construct(
        private readonly ImageUrlResolverInterface $imageUrls = new PassthroughImageUrlResolver(),
    ) {
    }

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

        $bg = $styling['backgroundColor'] ?? null;
        $tone = null;
        if (\is_string($bg) && $bg !== '') {
            $vars['--cb-s-bg'] = $bg;
            $tone = ColorTone::of($bg);
        }

        $image = $this->imageUrl($styling['backgroundImage'] ?? null);
        if ($image !== null) {
            $classes[] = 'cb-section--bg-image';
            $vars['--cb-s-bg-img'] = 'url("' . $image . '")';
            $size = $styling['backgroundSize'] ?? null;
            if (\is_string($size) && isset(self::BACKGROUND_SIZES[$size])) {
                $vars['--cb-s-bg-size'] = $size;
            }
            $position = $styling['backgroundPosition'] ?? null;
            if (\is_string($position) && isset(self::BACKGROUND_POSITIONS[$position])) {
                $vars['--cb-s-bg-pos'] = $position;
            }
            $opacity = $styling['overlayOpacity'] ?? null;
            $opacity = \is_int($opacity) || (\is_string($opacity) && ctype_digit($opacity))
                ? min(100, (int) $opacity)
                : 0;
            $veil = $styling['overlayColor'] ?? null;
            $veil = ColorTone::of($veil) !== null ? $veil : '#000000';
            if ($opacity > 0) {
                $vars['--cb-s-overlay'] = (string) ($opacity / 100);
                $vars['--cb-s-overlay-color'] = $veil;
            }
            // The photo hides the colour: only a veil dense enough to carry
            // the text says whether it reads dark or light.
            $tone = $opacity >= self::TONE_VEIL_OPACITY ? ColorTone::of($veil) : null;
        }

        if ($tone !== null) {
            $classes[] = 'cb-section--bg-' . $tone;
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

    /**
     * Through the host's image resolver, then made safe inside `url("…")`:
     * a quote or a bracket in a stored path must not close the value.
     */
    private function imageUrl(mixed $src): ?string
    {
        if (!\is_string($src) || trim($src) === '' || preg_match('/[\x00-\x1f]/', $src) === 1) {
            return null;
        }
        $src = trim($src);
        // A scheme other than http(s) has no business in a stylesheet.
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $src, $m) === 1 && !\in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return null;
        }

        $url = $this->imageUrls->resolve($src, self::BACKGROUND_WIDTH)->src;

        return strtr($url, ['"' => '%22', "'" => '%27', '(' => '%28', ')' => '%29', '\\' => '%5C', ' ' => '%20', ';' => '%3B']);
    }
}
