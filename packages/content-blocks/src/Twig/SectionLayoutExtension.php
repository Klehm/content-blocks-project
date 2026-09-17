<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Section\SectionLayout;
use ContentBlocks\Section\SectionLayoutRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_section_layouts()` for the add-section buttons, and the geometry of
 * the glyph each button draws from its column spans.
 */
final class SectionLayoutExtension extends AbstractExtension
{
    private const ICON_X = 3.0;
    private const ICON_WIDTH = 26.0;

    public function __construct(
        private readonly SectionLayoutRegistry $layouts,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_section_layouts', $this->layouts->all(...)),
            new TwigFunction('cb_section_layout_rects', self::iconRects(...)),
        ];
    }

    /**
     * One `<rect>` per column in a 32×24 viewBox, widths proportional to the
     * spans. The gap narrows past four columns so the glyph stays inside.
     *
     * @return list<array{x: float, width: float}>
     */
    public static function iconRects(SectionLayout $layout): array
    {
        $count = \count($layout->columns);
        if ($count === 0) {
            return [];
        }

        $gap = min(3.0, 12.0 / $count);
        $available = self::ICON_WIDTH - $gap * ($count - 1);
        $total = array_sum($layout->columns);

        $rects = [];
        $x = self::ICON_X;
        foreach ($layout->columns as $span) {
            $width = $available * $span / $total;
            $rects[] = ['x' => round($x, 3), 'width' => round($width, 3)];
            $x += $width + $gap;
        }

        return $rects;
    }
}
