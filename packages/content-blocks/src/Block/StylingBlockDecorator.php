<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\Entity\Block;

/**
 * Reads the `styling` sub-form and emits the custom properties and classes
 * `styling.css` maps to real properties.
 *
 * @see docs/internals/forms.md#the-styling-data-shape
 */
final class StylingBlockDecorator implements BlockDecoratorInterface
{
    private const SIDE_SHORT = ['top' => 't', 'right' => 'r', 'bottom' => 'b', 'left' => 'l'];
    // Data keys are spelled out; emitted CSS var names stay terse (see
    // StylingSectionDecorator). This map bridges the two.
    private const VIEWPORT_SHORT = ['desktop' => 'd', 'tablet' => 't', 'mobile' => 'm'];
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

        // Namespaced `--cb-b-*` so a section's `--cb-s-*` never inherits in.
        foreach (['padding' => 'b-pad', 'margin' => 'b-mar'] as $key => $short) {
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

        $bg = $styling['backgroundColor'] ?? null;
        if (\is_string($bg) && $bg !== '') {
            $vars['--cb-b-bg'] = $bg;
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

        if ($vars === []) {
            return new BlockDecoration();
        }

        return new BlockDecoration(classes: ['cb-block--styled'], inlineStyles: $vars);
    }
}
