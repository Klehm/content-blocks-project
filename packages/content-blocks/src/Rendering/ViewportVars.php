<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

/**
 * Per-viewport CSS variables, minus those the stylesheet's fallback chain
 * (mobile → tablet → desktop → default) already resolves to the same value.
 *
 * @internal
 */
final class ViewportVars
{
    private const SIDE_SHORT = ['top' => 't', 'right' => 'r', 'bottom' => 'b', 'left' => 'l'];
    private const VIEWPORT_SHORT = ['desktop' => 'd', 'tablet' => 't', 'mobile' => 'm'];

    /**
     * A padding or margin box: `{desktop: {top: 10, …}, tablet: …}`.
     *
     * @return array<string, string>|null null when no side holds a value
     */
    public static function box(mixed $responsive, string $prefix): ?array
    {
        if (!\is_array($responsive)) {
            return null;
        }
        $vars = [];
        $any = false;
        foreach (self::SIDE_SHORT as $side => $sideShort) {
            $inherited = 0;
            foreach (self::VIEWPORT_SHORT as $viewport => $vpShort) {
                $box = $responsive[$viewport] ?? null;
                $value = \is_array($box) ? ($box[$side] ?? null) : null;
                if (!\is_int($value)) {
                    continue;
                }
                $any = true;
                if ($value !== $inherited) {
                    $vars["--cb-{$prefix}-{$vpShort}-{$sideShort}"] = $value . 'px';
                }
                $inherited = $value;
            }
        }

        return $any ? $vars : null;
    }

    /**
     * One non-negative px value per viewport. The desktop value is always
     * emitted: the stylesheet's default (1rem) is not a px number.
     *
     * @return array<string, string>
     */
    public static function single(mixed $responsive, string $name): array
    {
        if (!\is_array($responsive)) {
            return [];
        }
        $vars = [];
        $inherited = null;
        foreach (self::VIEWPORT_SHORT as $viewport => $vpShort) {
            $value = $responsive[$viewport] ?? null;
            if (!\is_int($value) || $value < 0) {
                continue;
            }
            if ($value !== $inherited) {
                $vars["--cb-{$name}-{$vpShort}"] = $value . 'px';
            }
            $inherited = $value;
        }

        return $vars;
    }
}
