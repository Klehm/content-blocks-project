<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Entity\Section;
use ContentBlocks\Section\StylingSectionDecorator;
use PHPUnit\Framework\TestCase;

final class StylingSectionDecoratorTest extends TestCase
{
    public function testEmptySettingsProduceNoDecoration(): void
    {
        $decoration = (new StylingSectionDecorator())->decorate([], new Section());

        $this->assertSame([], $decoration->classes);
        $this->assertSame([], $decoration->inlineStyles);
    }

    public function testEmptyStylingProduceNoDecoration(): void
    {
        $decoration = (new StylingSectionDecorator())->decorate(['styling' => []], new Section());

        $this->assertSame([], $decoration->classes);
        $this->assertSame([], $decoration->inlineStyles);
    }

    public function testPaddingEmitsCssVarsPerViewportAndSide(): void
    {
        $settings = [
            'styling' => [
                'padding' => [
                    'desktop' => ['top' => 10, 'right' => 20, 'bottom' => 30, 'left' => 40, 'linked' => false],
                    'mobile' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5, 'linked' => true],
                ],
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertSame('10px', $decoration->inlineStyles['--cb-s-pad-d-t']);
        $this->assertSame('20px', $decoration->inlineStyles['--cb-s-pad-d-r']);
        $this->assertSame('30px', $decoration->inlineStyles['--cb-s-pad-d-b']);
        $this->assertSame('40px', $decoration->inlineStyles['--cb-s-pad-d-l']);
        $this->assertSame('5px', $decoration->inlineStyles['--cb-s-pad-m-t']);
        $this->assertArrayNotHasKey('--cb-s-pad-t-t', $decoration->inlineStyles, 'tablet was unset');
        $this->assertContains('cb-section--styled', $decoration->classes);
    }

    public function testMarginEmitsCssVarsUnderDifferentShortName(): void
    {
        $settings = [
            'styling' => [
                'margin' => [
                    'desktop' => ['top' => -8, 'right' => 0, 'bottom' => 0, 'left' => 0, 'linked' => false],
                ],
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertSame('-8px', $decoration->inlineStyles['--cb-s-mar-d-t']);
    }

    public function testBackgroundColorEmitsVarOnlyWhenNonEmpty(): void
    {
        $deco = (new StylingSectionDecorator());

        $a = $deco->decorate(['styling' => ['backgroundColor' => '#ff0000']], new Section());
        $b = $deco->decorate(['styling' => ['backgroundColor' => '']], new Section());

        $this->assertSame('#ff0000', $a->inlineStyles['--cb-s-bg']);
        $this->assertArrayNotHasKey('--cb-s-bg', $b->inlineStyles);
    }

    public function testGapEmitsPerViewportPxVars(): void
    {
        $settings = [
            'styling' => [
                'gap' => ['desktop' => 24, 'mobile' => 8],
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertSame('24px', $decoration->inlineStyles['--cb-gap-d']);
        $this->assertSame('8px', $decoration->inlineStyles['--cb-gap-m']);
        $this->assertArrayNotHasKey('--cb-gap-t', $decoration->inlineStyles, 'tablet was unset');
    }

    public function testGapIgnoresNonIntAndNegativeValues(): void
    {
        $deco = (new StylingSectionDecorator());

        $decoration = $deco->decorate(['styling' => ['gap' => ['desktop' => -4, 'tablet' => null, 'mobile' => 12]]], new Section());

        $this->assertArrayNotHasKey('--cb-gap-d', $decoration->inlineStyles);
        $this->assertArrayNotHasKey('--cb-gap-t', $decoration->inlineStyles);
        $this->assertSame('12px', $decoration->inlineStyles['--cb-gap-m']);
    }

    public function testMinHeightAppendsUnit(): void
    {
        $deco = (new StylingSectionDecorator());

        $px = $deco->decorate(['styling' => ['minHeight' => ['value' => 400, 'unit' => 'px']]], new Section());
        $vh = $deco->decorate(['styling' => ['minHeight' => ['value' => 100, 'unit' => 'vh']]], new Section());

        $this->assertSame('400px', $px->inlineStyles['--cb-min-h']);
        $this->assertSame('100vh', $vh->inlineStyles['--cb-min-h']);
    }

    public function testMinHeightIgnoresZeroAndInvalidUnit(): void
    {
        $deco = (new StylingSectionDecorator());

        $a = $deco->decorate(['styling' => ['minHeight' => ['value' => 0, 'unit' => 'px']]], new Section());
        $b = $deco->decorate(['styling' => ['minHeight' => ['value' => 100, 'unit' => 'rem']]], new Section());

        $this->assertArrayNotHasKey('--cb-min-h', $a->inlineStyles);
        $this->assertArrayNotHasKey('--cb-min-h', $b->inlineStyles);
    }

    public function testVerticalAlignMapsAndTogglesClass(): void
    {
        $deco = (new StylingSectionDecorator());

        $center = $deco->decorate(['styling' => ['verticalAlign' => 'center']], new Section());
        $end = $deco->decorate(['styling' => ['verticalAlign' => 'end']], new Section());

        $this->assertSame('center', $center->inlineStyles['--cb-valign']);
        $this->assertContains('cb-section--has-valign', $center->classes);
        $this->assertSame('flex-end', $end->inlineStyles['--cb-valign']);
    }

    public function testVerticalAlignRejectsUnknownValues(): void
    {
        $deco = (new StylingSectionDecorator());

        $decoration = $deco->decorate(['styling' => ['verticalAlign' => 'space-between']], new Section());

        $this->assertArrayNotHasKey('--cb-valign', $decoration->inlineStyles);
    }

    /** The host styles text over the background from these classes. */
    public function testTheBackgroundToneIsAClass(): void
    {
        $deco = new StylingSectionDecorator();

        $dark = $deco->decorate(['styling' => ['backgroundColor' => '#1e293b']], new Section());
        $light = $deco->decorate(['styling' => ['backgroundColor' => '#f1f5f9']], new Section());
        $none = $deco->decorate(['styling' => ['backgroundColor' => '']], new Section());

        $this->assertContains('cb-section--bg-dark', $dark->classes);
        $this->assertContains('cb-section--bg-light', $light->classes);
        $this->assertSame([], $none->classes);
    }

    public function testABackgroundImageEmitsItsVariablesAndClass(): void
    {
        $decoration = (new StylingSectionDecorator())->decorate(['styling' => [
            'backgroundImage' => '/uploads/hero.jpg',
            'backgroundSize' => 'contain',
            'backgroundPosition' => 'top',
        ]], new Section());

        $this->assertContains('cb-section--bg-image', $decoration->classes);
        $this->assertSame('url("/uploads/hero.jpg")', $decoration->inlineStyles['--cb-s-bg-img']);
        $this->assertSame('contain', $decoration->inlineStyles['--cb-s-bg-size']);
        $this->assertSame('top', $decoration->inlineStyles['--cb-s-bg-pos']);
        $this->assertArrayNotHasKey('--cb-s-overlay', $decoration->inlineStyles);
    }

    /** The host's resolver (CDN, LiipImagine) decides the URL. */
    public function testTheImageGoesThroughTheResolver(): void
    {
        $resolver = new class () implements \ContentBlocks\Image\ImageUrlResolverInterface {
            public ?int $width = null;

            public function resolve(string $src, ?int $width = null, ?int $height = null): \ContentBlocks\Image\ResolvedImage
            {
                $this->width = $width;

                return new \ContentBlocks\Image\ResolvedImage('https://cdn.test' . $src);
            }
        };

        $decoration = (new StylingSectionDecorator($resolver))
            ->decorate(['styling' => ['backgroundImage' => '/uploads/a.jpg']], new Section());

        $this->assertSame('url("https://cdn.test/uploads/a.jpg")', $decoration->inlineStyles['--cb-s-bg-img']);
        $this->assertSame(1920, $resolver->width);
    }

    /** A stored path lands inside `url("…")` in a style attribute. */
    public function testTheImageUrlCannotBreakOutOfItsValue(): void
    {
        $deco = new StylingSectionDecorator();

        $quoted = $deco->decorate(['styling' => ['backgroundImage' => '/a b"); color: red; x("']], new Section());
        $script = $deco->decorate(['styling' => ['backgroundImage' => 'javascript:alert(1)']], new Section());
        $data = $deco->decorate(['styling' => ['backgroundImage' => 'data:image/png;base64,AAA']], new Section());

        $this->assertSame('url("/a%20b%22%29%3B%20color:%20red%3B%20x%28%22")', $quoted->inlineStyles['--cb-s-bg-img']);
        $this->assertArrayNotHasKey('--cb-s-bg-img', $script->inlineStyles);
        $this->assertArrayNotHasKey('--cb-s-bg-img', $data->inlineStyles);
    }

    public function testAVeilSetsTheToneOverTheColour(): void
    {
        $deco = new StylingSectionDecorator();
        $image = ['backgroundImage' => '/uploads/a.jpg', 'backgroundColor' => '#ffffff'];

        $dense = $deco->decorate(['styling' => $image + ['overlayOpacity' => 50]], new Section());
        $light = $deco->decorate(['styling' => $image + ['overlayOpacity' => 60, 'overlayColor' => '#fafafa']], new Section());
        $faint = $deco->decorate(['styling' => $image + ['overlayOpacity' => 20]], new Section());

        $this->assertSame('0.5', $dense->inlineStyles['--cb-s-overlay']);
        $this->assertSame('#000000', $dense->inlineStyles['--cb-s-overlay-color']);
        $this->assertContains('cb-section--bg-dark', $dense->classes);
        $this->assertContains('cb-section--bg-light', $light->classes);
        // Under a faint veil the photo decides, and nobody knows its tone.
        $this->assertNotContains('cb-section--bg-light', $faint->classes);
        $this->assertNotContains('cb-section--bg-dark', $faint->classes);
    }

    public function testFullPayloadProducesStableOutput(): void
    {
        $settings = [
            'styling' => [
                'padding' => [
                    'desktop' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10, 'linked' => true],
                ],
                'backgroundColor' => '#0a0a0a',
                'minHeight' => ['value' => 500, 'unit' => 'px'],
                'verticalAlign' => 'center',
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertEqualsCanonicalizing(
            ['cb-section--bg-dark', 'cb-section--has-valign', 'cb-section--styled'],
            $decoration->classes,
        );
        $this->assertSame('10px', $decoration->inlineStyles['--cb-s-pad-d-t']);
        $this->assertSame('#0a0a0a', $decoration->inlineStyles['--cb-s-bg']);
        $this->assertSame('500px', $decoration->inlineStyles['--cb-min-h']);
        $this->assertSame('center', $decoration->inlineStyles['--cb-valign']);
    }
}
