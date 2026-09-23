<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Controller\ColumnsController;
use ContentBlocks\Section\RestoredStructure;
use PHPUnit\Framework\TestCase;

final class RestoredStructureTest extends TestCase
{
    public function testAPageSizedPayloadFits(): void
    {
        $this->assertFalse(RestoredStructure::tooLarge([
            ['columns' => [['blocks' => [[], []]], ['blocks' => [[]]]]],
            'not-a-section',
        ]));
    }

    // Each block is replayed through its form: the count is the cost.
    public function testTooManyBlocksOverallIsTooLarge(): void
    {
        $blocks = array_fill(0, RestoredStructure::MAX_BLOCKS / 2 + 1, []);
        $section = ['columns' => [['blocks' => $blocks]]];

        $this->assertTrue(RestoredStructure::tooLarge([$section, $section]));
    }

    public function testMoreColumnsThanTheBuilderAllowsIsTooLarge(): void
    {
        $columns = array_fill(0, ColumnsController::MAX_COLUMNS + 1, ['blocks' => []]);

        $this->assertTrue(RestoredStructure::tooLarge([['columns' => $columns]]));
    }

    public function testTooManySectionsIsTooLarge(): void
    {
        $this->assertTrue(RestoredStructure::tooLarge(
            array_fill(0, RestoredStructure::MAX_SECTIONS + 1, ['columns' => []]),
        ));
    }
}
