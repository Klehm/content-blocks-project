<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Block;

use ContentBlocks\Block\StylingBlockDecorator;
use ContentBlocks\Entity\Block;
use PHPUnit\Framework\TestCase;

final class StylingBlockDecoratorTest extends TestCase
{
    public function testEmptyDataProduceNoDecoration(): void
    {
        $decoration = (new StylingBlockDecorator())->decorate([], new Block());

        $this->assertSame([], $decoration->classes);
        $this->assertSame([], $decoration->inlineStyles);
    }

    public function testPaddingAndMarginEmitVars(): void
    {
        $data = [
            'styling' => [
                'padding' => [
                    'd' => ['top' => 10, 'right' => 20, 'bottom' => 10, 'left' => 20, 'linked' => false],
                ],
                'margin' => [
                    'm' => ['top' => 5, 'right' => 0, 'bottom' => 5, 'left' => 0, 'linked' => false],
                ],
            ],
        ];

        $decoration = (new StylingBlockDecorator())->decorate($data, new Block());

        $this->assertSame('10px', $decoration->inlineStyles['--cb-pad-d-t']);
        $this->assertSame('20px', $decoration->inlineStyles['--cb-pad-d-r']);
        $this->assertSame('5px', $decoration->inlineStyles['--cb-mar-m-t']);
        $this->assertSame('0px', $decoration->inlineStyles['--cb-mar-m-r']);
        $this->assertContains('cb-block--styled', $decoration->classes);
    }

    public function testBackgroundColorEmitsBgVar(): void
    {
        $decoration = (new StylingBlockDecorator())->decorate(
            ['styling' => ['backgroundColor' => '#abc']],
            new Block(),
        );

        $this->assertSame('#abc', $decoration->inlineStyles['--cb-bg']);
    }

    public function testMaxWidthEmitsMaxWidthVar(): void
    {
        $decoration = (new StylingBlockDecorator())->decorate(
            ['styling' => ['maxWidth' => ['value' => 720, 'unit' => 'px']]],
            new Block(),
        );

        $this->assertSame('720px', $decoration->inlineStyles['--cb-max-w']);
    }

    public function testMaxWidthIgnoresZeroOrMissing(): void
    {
        $deco = (new StylingBlockDecorator());

        $a = $deco->decorate(['styling' => ['maxWidth' => ['value' => 0, 'unit' => 'px']]], new Block());
        $b = $deco->decorate(['styling' => ['maxWidth' => []]], new Block());

        $this->assertArrayNotHasKey('--cb-max-w', $a->inlineStyles);
        $this->assertArrayNotHasKey('--cb-max-w', $b->inlineStyles);
    }

    public function testBlockStylingDoesNotEmitFlexAlignmentVars(): void
    {
        // Alignment / minHeight are section-level concerns — even if a
        // user-extended StylingType somehow leaked these into a block's
        // data, the block decorator must not turn them into CSS vars.
        $decoration = (new StylingBlockDecorator())->decorate(
            ['styling' => [
                'verticalAlign' => 'center',
                'horizontalAlign' => 'space-between',
                'minHeight' => ['value' => 400, 'unit' => 'px'],
            ]],
            new Block(),
        );

        $this->assertArrayNotHasKey('--cb-valign', $decoration->inlineStyles);
        $this->assertArrayNotHasKey('--cb-halign', $decoration->inlineStyles);
        $this->assertArrayNotHasKey('--cb-min-h', $decoration->inlineStyles);
        $this->assertSame([], $decoration->classes);
    }
}
