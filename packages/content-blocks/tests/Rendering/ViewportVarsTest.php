<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use ContentBlocks\Rendering\ViewportVars;
use PHPUnit\Framework\TestCase;

final class ViewportVarsTest extends TestCase
{
    public function testAZeroOverridingANonZeroViewportIsKept(): void
    {
        $vars = ViewportVars::box([
            'desktop' => ['top' => 40],
            'tablet' => ['top' => 0],
            'mobile' => ['top' => 0],
        ], 'b-pad');

        $this->assertSame(['--cb-b-pad-d-t' => '40px', '--cb-b-pad-t-t' => '0px'], $vars);
    }

    public function testAnUnsetTabletLetsMobileCompareWithDesktop(): void
    {
        $vars = ViewportVars::box([
            'desktop' => ['left' => 16],
            'mobile' => ['left' => 16],
        ], 's-mar');

        $this->assertSame(['--cb-s-mar-d-l' => '16px'], $vars);
    }

    public function testNoValueAtAllIsNullNotAnEmptyBox(): void
    {
        $this->assertNull(ViewportVars::box(['desktop' => ['top' => null]], 'b-pad'));
        $this->assertNull(ViewportVars::box('junk', 'b-pad'));
        $this->assertSame([], ViewportVars::box(['desktop' => ['top' => 0]], 'b-pad'));
    }

    public function testDesktopGapIsAlwaysEmittedSinceTheDefaultIsInRem(): void
    {
        $this->assertSame(['--cb-gap-d' => '0px'], ViewportVars::single(['desktop' => 0, 'tablet' => 0], 'gap'));
        $this->assertSame(['--cb-gap-t' => '8px'], ViewportVars::single(['desktop' => -1, 'tablet' => 8], 'gap'));
    }
}
