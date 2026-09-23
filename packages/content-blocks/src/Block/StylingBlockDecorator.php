<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\Entity\Block;
use ContentBlocks\Palette\ColorTone;
use ContentBlocks\Palette\CssColor;
use ContentBlocks\Rendering\ViewportVars;

/**
 * Reads the `styling` sub-form and emits the custom properties and classes
 * `styling.css` maps to real properties.
 *
 * @see docs/internals/forms.md#the-styling-data-shape
 */
final class StylingBlockDecorator implements BlockDecoratorInterface
{
    private const TEXT_ALIGNS = ['start', 'center', 'end', 'justify'];
    private const ALIGN_SELF_MAP = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
    ];

    public function decorate(array $data, Block $block): BlockDecoration
    {
        $styling = $data['styling'] ?? null;
        if (!\is_array($styling) || $styling === []) {
            return new BlockDecoration();
        }

        $vars = [];
        $classes = [];
        $spaced = false;

        // Namespaced `--cb-b-*` so a section's `--cb-s-*` never inherits in.
        foreach (['padding' => 'b-pad', 'margin' => 'b-mar'] as $key => $short) {
            $box = ViewportVars::box($styling[$key] ?? null, $short);
            if ($box !== null) {
                $spaced = true;
                $vars += $box;
            }
        }

        $bg = CssColor::safe($styling['backgroundColor'] ?? null);
        if ($bg !== null) {
            $vars['--cb-b-bg'] = $bg;
            $tone = ColorTone::of($bg);
            if ($tone !== null) {
                $classes[] = 'cb-block--bg-' . $tone;
            }
        }

        // A class, not a variable: a nested block would inherit the variable.
        $textAlign = $styling['textAlign'] ?? null;
        if (\is_string($textAlign) && \in_array($textAlign, self::TEXT_ALIGNS, true)) {
            $classes[] = 'cb-block--text-' . $textAlign;
        }

        $maxWidth = $styling['maxWidth'] ?? null;
        $hasMaxWidth = false;
        if (\is_array($maxWidth)) {
            $val = $maxWidth['value'] ?? null;
            if (\is_int($val) && $val > 0) {
                $vars['--cb-max-w'] = $val . 'px';
                $hasMaxWidth = true;
            }
        }

        // Meaningless without a constrained width: the block would stretch
        // to fill the column anyway.
        if ($hasMaxWidth) {
            $alignSelf = $styling['alignSelf'] ?? null;
            if (\is_string($alignSelf) && isset(self::ALIGN_SELF_MAP[$alignSelf])) {
                $vars['--cb-align-self'] = self::ALIGN_SELF_MAP[$alignSelf];
            }
        }

        // `cb-block--styled` zeroes padding and margin, so only with values;
        // an all-zero box emits no variable but is one.
        if ($vars !== [] || $spaced) {
            array_unshift($classes, 'cb-block--styled');
        }

        return new BlockDecoration(classes: $classes, inlineStyles: $vars);
    }
}
