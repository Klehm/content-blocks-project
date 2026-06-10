<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\Entity\Block;

/**
 * Reads the `styling` sub-form (added by BlockFormType) and emits CSS
 * custom properties + utility classes that the package's `styling.css`
 * stylesheet maps to real properties — block-side mirror of
 * {@see \ContentBlocks\Section\StylingSectionDecorator}.
 *
 * Block styling covers padding, margin, backgroundColor and maxWidth.
 * Per-viewport overrides for padding/margin are routed through the same
 * @media chain as sections; maxWidth and backgroundColor are not
 * responsive in this iteration.
 *
 * Data shape (under `$data['styling']`):
 *  - padding, margin: { d: BoxSpacing, t: BoxSpacing, m: BoxSpacing }
 *      where BoxSpacing = { top, right, bottom, left: int, linked: bool }
 *  - backgroundColor: string (#hex)
 *  - maxWidth: { value: int, unit: 'px' }
 *  - alignSelf: 'start'|'center'|'end' (only honored when maxWidth is set)
 */
final class StylingBlockDecorator implements BlockDecoratorInterface
{
    private const SIDE_SHORT = ['top' => 't', 'right' => 'r', 'bottom' => 'b', 'left' => 'l'];
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

        // Block vars are namespaced `--cb-b-*` so a section's `--cb-s-*`
        // padding/margin/background never inherits into the block; see styling.css.
        foreach (['padding' => 'b-pad', 'margin' => 'b-mar'] as $key => $short) {
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

        // align-self is only meaningful when the block has a constrained
        // width — otherwise the block stretches to fill the column and
        // the cross-axis position has no visible effect. Skipping the
        // var when maxWidth is unset keeps the output minimal.
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
