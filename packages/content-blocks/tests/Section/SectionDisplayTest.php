<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Section\SectionDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SectionDisplayTest extends TestCase
{
    public function testAMissingViewportInheritsTheOneAbove(): void
    {
        $this->assertSame(
            ['desktop' => 'grid', 'tablet' => 'grid', 'mobile' => 'grid'],
            SectionDisplay::resolve([]),
        );
        $this->assertSame(
            ['desktop' => 'grid', 'tablet' => 'slider', 'mobile' => 'slider'],
            SectionDisplay::resolve(['displayTablet' => 'slider']),
        );
        $this->assertSame(
            ['desktop' => 'grid', 'tablet' => 'grid', 'mobile' => 'slider'],
            SectionDisplay::resolve(['displayMobile' => 'slider']),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<string, mixed>,
     *     array{desktop: string, tablet: string, mobile: string},
     * }>
     */
    public static function clampedCombinations(): iterable
    {
        yield 'tabs never below a grid' => [
            ['display' => 'grid', 'displayMobile' => 'tabs'],
            ['desktop' => 'grid', 'tablet' => 'grid', 'mobile' => 'grid'],
        ];
        yield 'tabs turn into an accordion' => [
            ['display' => 'tabs', 'displayMobile' => 'accordion'],
            ['desktop' => 'tabs', 'tablet' => 'tabs', 'mobile' => 'accordion'],
        ];
        yield 'tabs never become a grid' => [
            ['display' => 'tabs', 'displayTablet' => 'grid', 'displayMobile' => 'slider'],
            ['desktop' => 'tabs', 'tablet' => 'tabs', 'mobile' => 'tabs'],
        ];
        yield 'an accordion stays one' => [
            ['display' => 'accordion', 'displayMobile' => 'grid'],
            ['desktop' => 'accordion', 'tablet' => 'accordion', 'mobile' => 'accordion'],
        ];
        yield 'mobile is clamped by the tablet value' => [
            ['display' => 'slider', 'displayTablet' => 'accordion', 'displayMobile' => 'grid'],
            ['desktop' => 'slider', 'tablet' => 'accordion', 'mobile' => 'accordion'],
        ];
        yield 'a slider becomes a grid' => [
            ['display' => 'slider', 'displayMobile' => 'grid'],
            ['desktop' => 'slider', 'tablet' => 'slider', 'mobile' => 'grid'],
        ];
        yield 'unknown values inherit' => [
            ['display' => 'carousel', 'displayTablet' => 'inherit', 'displayMobile' => 42],
            ['desktop' => 'grid', 'tablet' => 'grid', 'mobile' => 'grid'],
        ];
    }

    /**
     * @param array<string, mixed>                                  $settings
     * @param array{desktop: string, tablet: string, mobile: string} $expected
     */
    #[DataProvider('clampedCombinations')]
    public function testANarrowerViewportCanOnlyGetMoreCompact(array $settings, array $expected): void
    {
        $this->assertSame($expected, SectionDisplay::resolve($settings));
    }

    public function testNormalizeKeepsOnlyWhatDiffersFromTheViewportAbove(): void
    {
        $this->assertSame(
            ['display' => 'grid', 'displayMobile' => 'slider'],
            SectionDisplay::normalize([
                'display' => 'grid',
                'displayTablet' => 'inherit',
                'displayMobile' => 'slider',
            ]),
        );
        $this->assertSame(
            ['display' => 'tabs'],
            SectionDisplay::normalize([
                'display' => 'tabs',
                'displayTablet' => 'grid',
                'displayMobile' => 'tabs',
            ]),
        );
        $this->assertSame(
            ['display' => 'grid', 'displayTablet' => 'slider', 'displayMobile' => 'grid'],
            SectionDisplay::normalize([
                'display' => 'grid',
                'displayTablet' => 'slider',
                'displayMobile' => 'grid',
            ]),
        );
    }

    public function testSlidesPerViewInheritDownwardsAndStayInRange(): void
    {
        $this->assertSame(
            ['desktop' => 1, 'tablet' => 1, 'mobile' => 1],
            SectionDisplay::perView([]),
        );
        $this->assertSame(
            ['desktop' => 3, 'tablet' => 3, 'mobile' => 1],
            SectionDisplay::perView(['sliderPerView' => ['desktop' => 3, 'mobile' => '1']]),
        );
        $this->assertSame(
            ['desktop' => 6, 'tablet' => 6, 'mobile' => 6],
            SectionDisplay::perView(['sliderPerView' => ['desktop' => 40, 'tablet' => 0, 'mobile' => 'x']]),
        );
    }

    public function testSliderOptionsFallBackOnSafeValues(): void
    {
        $this->assertSame(
            ['controls' => 'both', 'autoplay' => 0, 'loop' => false],
            SectionDisplay::sliderOptions(['sliderControls' => 'buttons', 'sliderAutoplay' => -3]),
        );
        $this->assertSame(
            ['controls' => 'dots', 'autoplay' => 60, 'loop' => true],
            SectionDisplay::sliderOptions([
                'sliderControls' => 'dots',
                'sliderAutoplay' => '600',
                'sliderLoop' => true,
            ]),
        );
    }

    public function testOnlyTabsAndAccordionPrintTitles(): void
    {
        $this->assertTrue(SectionDisplay::showsTitles('tabs'));
        $this->assertTrue(SectionDisplay::showsTitles('accordion'));
        $this->assertFalse(SectionDisplay::showsTitles('grid'));
        $this->assertFalse(SectionDisplay::showsTitles('slider'));
    }
}
