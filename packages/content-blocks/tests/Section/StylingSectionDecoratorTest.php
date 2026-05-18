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
                    'd' => ['top' => 10, 'right' => 20, 'bottom' => 30, 'left' => 40, 'linked' => false],
                    'm' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5, 'linked' => true],
                ],
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertSame('10px', $decoration->inlineStyles['--cb-pad-d-t']);
        $this->assertSame('20px', $decoration->inlineStyles['--cb-pad-d-r']);
        $this->assertSame('30px', $decoration->inlineStyles['--cb-pad-d-b']);
        $this->assertSame('40px', $decoration->inlineStyles['--cb-pad-d-l']);
        $this->assertSame('5px', $decoration->inlineStyles['--cb-pad-m-t']);
        $this->assertArrayNotHasKey('--cb-pad-t-t', $decoration->inlineStyles, 'tablet was unset');
        $this->assertContains('cb-section--styled', $decoration->classes);
    }

    public function testMarginEmitsCssVarsUnderDifferentShortName(): void
    {
        $settings = [
            'styling' => [
                'margin' => [
                    'd' => ['top' => -8, 'right' => 0, 'bottom' => 0, 'left' => 0, 'linked' => false],
                ],
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertSame('-8px', $decoration->inlineStyles['--cb-mar-d-t']);
    }

    public function testBackgroundColorEmitsVarOnlyWhenNonEmpty(): void
    {
        $deco = (new StylingSectionDecorator());

        $a = $deco->decorate(['styling' => ['backgroundColor' => '#ff0000']], new Section());
        $b = $deco->decorate(['styling' => ['backgroundColor' => '']], new Section());

        $this->assertSame('#ff0000', $a->inlineStyles['--cb-bg']);
        $this->assertArrayNotHasKey('--cb-bg', $b->inlineStyles);
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

    public function testVerticalAlignRejectsSpaceValues(): void
    {
        $deco = (new StylingSectionDecorator());

        $decoration = $deco->decorate(['styling' => ['verticalAlign' => 'space-between']], new Section());

        // space-between has no meaning along a single-child flex-column;
        // the decorator rejects it for verticalAlign even though the form
        // theoretically allows it.
        $this->assertArrayNotHasKey('--cb-valign', $decoration->inlineStyles);
    }

    public function testHorizontalAlignAcceptsAllChoicesIncludingSpaceValues(): void
    {
        $deco = (new StylingSectionDecorator());

        foreach ([
            'start' => 'flex-start',
            'center' => 'center',
            'end' => 'flex-end',
            'space-between' => 'space-between',
            'space-around' => 'space-around',
        ] as $input => $cssValue) {
            $d = $deco->decorate(['styling' => ['horizontalAlign' => $input]], new Section());
            $this->assertSame($cssValue, $d->inlineStyles['--cb-halign'], "horizontalAlign=$input");
            $this->assertContains('cb-section--has-halign', $d->classes);
        }
    }

    public function testFullPayloadProducesStableOutput(): void
    {
        $settings = [
            'styling' => [
                'padding' => [
                    'd' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10, 'linked' => true],
                ],
                'backgroundColor' => '#0a0a0a',
                'minHeight' => ['value' => 500, 'unit' => 'px'],
                'verticalAlign' => 'center',
                'horizontalAlign' => 'space-between',
            ],
        ];

        $decoration = (new StylingSectionDecorator())->decorate($settings, new Section());

        $this->assertEqualsCanonicalizing(
            ['cb-section--has-valign', 'cb-section--has-halign', 'cb-section--styled'],
            $decoration->classes,
        );
        $this->assertSame('10px', $decoration->inlineStyles['--cb-pad-d-t']);
        $this->assertSame('#0a0a0a', $decoration->inlineStyles['--cb-bg']);
        $this->assertSame('500px', $decoration->inlineStyles['--cb-min-h']);
        $this->assertSame('center', $decoration->inlineStyles['--cb-valign']);
        $this->assertSame('space-between', $decoration->inlineStyles['--cb-halign']);
    }
}
