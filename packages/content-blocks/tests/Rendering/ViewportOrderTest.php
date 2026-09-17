<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use ContentBlocks\Rendering\ViewportOrder;
use PHPUnit\Framework\TestCase;

final class ViewportOrderTest extends TestCase
{
    public function testAGroupWithoutRanksGetsNoVariables(): void
    {
        $this->assertSame([[], [], []], ViewportOrder::variables([[], [], []]));
    }

    public function testRankedSiblingsFollowTheirRanks(): void
    {
        $vars = ViewportOrder::variables([
            ['mobile' => 2],
            ['mobile' => 0],
            ['mobile' => 1],
        ]);

        $this->assertSame([
            ['--cb-order-m' => '2'],
            ['--cb-order-m' => '0'],
            ['--cb-order-m' => '1'],
        ], $vars);
    }

    /** Duplicate, paste or add: the newcomer has no rank. */
    public function testAnUnrankedSiblingFollowsItsDesktopPredecessor(): void
    {
        // Desktop A B C D; mobile ranks put C first. B was added later.
        $sequence = ViewportOrder::sequence(
            [0 => 1, 1 => null, 2 => 0, 3 => 2],
            [0, 1, 2, 3],
        );

        $this->assertSame([2, 0, 1, 3], $sequence);
    }

    public function testAnUnrankedFirstSiblingStaysFirst(): void
    {
        $this->assertSame([0, 2, 1], ViewportOrder::sequence([0 => null, 1 => 1, 2 => 0], [0, 1, 2]));
    }

    /** A duplicate copies its source's rank: the tie keeps it after. */
    public function testEqualRanksKeepTheBaseOrder(): void
    {
        $this->assertSame([1, 2, 0], ViewportOrder::sequence([0 => 1, 1 => 0, 2 => 0], [0, 1, 2]));
    }

    public function testMobileBuildsOnTheTabletOrder(): void
    {
        // Tablet: C A B. Mobile ranks B before A and leaves C unranked, so C
        // keeps its tablet place, first.
        $vars = ViewportOrder::variables([
            ['tablet' => 1, 'mobile' => 1],
            ['tablet' => 2, 'mobile' => 0],
            ['tablet' => 0],
        ]);

        $this->assertSame(['1', '2', '0'], array_column($vars, '--cb-order-t'));
        $this->assertSame('0', $vars[2]['--cb-order-m']);
        $this->assertSame('1', $vars[1]['--cb-order-m']);
        $this->assertSame('2', $vars[0]['--cb-order-m']);
    }

    public function testRanksAreReadDefensively(): void
    {
        $this->assertSame([], ViewportOrder::ranks(null));
        $this->assertSame([], ViewportOrder::ranks(['_order' => 'mobile']));
        $this->assertSame(
            ['mobile' => 3],
            ViewportOrder::ranks(['_order' => ['tablet' => -1, 'mobile' => 3, 'desktop' => 0]]),
        );
    }

    public function testWithRankSetsAndClearsOneViewport(): void
    {
        $holder = ViewportOrder::withRank(['title' => 'x'], 'mobile', 2);
        $this->assertSame(['title' => 'x', '_order' => ['mobile' => 2]], $holder);

        $holder = ViewportOrder::withRank($holder, 'tablet', 0);
        $this->assertSame(['tablet' => 0, 'mobile' => 2], ViewportOrder::ranks($holder));

        $holder = ViewportOrder::withRank(ViewportOrder::withRank($holder, 'mobile', null), 'tablet', null);
        $this->assertSame(['title' => 'x'], $holder);

        $this->assertSame(['title' => 'x'], ViewportOrder::withRank(['title' => 'x'], 'desktop', 1));
    }

    public function testStyleStringMatchesTheDecorationFormat(): void
    {
        $this->assertSame(
            '--cb-order-t:1;--cb-order-m:0;',
            ViewportOrder::styleString(['--cb-order-t' => '1', '--cb-order-m' => '0']),
        );
    }
}
