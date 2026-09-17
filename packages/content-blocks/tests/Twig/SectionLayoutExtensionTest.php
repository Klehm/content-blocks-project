<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Section\SectionLayout;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Twig\SectionLayoutExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SectionLayoutExtensionTest extends TestCase
{
    /** The built-in glyphs keep the geometry they were hand-drawn with. */
    public function testBuiltInGlyphsMatchTheHandDrawnOnes(): void
    {
        $this->assertSame([['x' => 3.0, 'width' => 26.0]], $this->rects([12]));
        $this->assertSame(
            [['x' => 3.0, 'width' => 11.5], ['x' => 17.5, 'width' => 11.5]],
            $this->rects([6, 6]),
        );
        $this->assertSame(
            [
                ['x' => 3.0, 'width' => 6.667],
                ['x' => 12.667, 'width' => 6.667],
                ['x' => 22.333, 'width' => 6.667],
            ],
            $this->rects([4, 4, 4]),
        );
    }

    public function testUnevenSpansDrawProportionalColumns(): void
    {
        $rects = $this->rects([4, 8]);

        $this->assertSame(7.667, $rects[0]['width']);
        $this->assertSame(15.333, $rects[1]['width']);
    }

    /** Twelve columns would overflow a 3-unit gap. */
    public function testTheGlyphStaysInsideTheViewBox(): void
    {
        $rects = $this->rects(array_fill(0, 12, 1));
        $last = $rects[11];

        $this->assertEqualsWithDelta(29.0, $last['x'] + $last['width'], 0.01);
        $this->assertGreaterThan(0, $last['width']);
    }

    public function testTheSidebarOffersAConfiguredFourColumnLayout(): void
    {
        $registry = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'three_cols' => ['enabled' => false],
            'four_cols' => ['label' => 'Four columns', 'columns' => [3, 3, 3, 3]],
        ]));

        $html = $this->twig($registry)->render('@ContentBlocks/builder/sidebar_empty.html.twig');

        $this->assertStringContainsString('data-cb-builder-layout-param="four_cols"', $html);
        $this->assertStringContainsString('title="Four columns"', $html);
        $this->assertSame(4, substr_count(
            $this->between($html, 'layout-param="four_cols"', '</button>'),
            '<rect ',
        ));
        $this->assertStringNotContainsString('layout-param="three_cols"', $html);
    }

    /**
     * @param list<int> $columns
     *
     * @return list<array{x: float, width: float}>
     */
    private function rects(array $columns): array
    {
        return SectionLayoutExtension::iconRects(new SectionLayout('l', 'L', $columns));
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $this->assertNotFalse($from);
        $to = strpos($haystack, $end, $from);
        $this->assertNotFalse($to);

        return substr($haystack, $from, $to - $from);
    }

    private function twig(SectionLayoutRegistry $registry): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ContentBlocks');

        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $env->addExtension(new SectionLayoutExtension($registry));

        return $env;
    }
}
