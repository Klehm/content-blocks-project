<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Content;

use ContentBlocks\Content\ContentManipulationException;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Publishing\ContentAreaPublisher;
use ContentBlocks\Section\BlockCloneObserverCollection;
use ContentBlocks\Section\BlockCloneObserverInterface;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Tests\Controller\ControllerTestCase;
use ContentBlocks\Tests\Controller\FakeBlockType;

/**
 * Content built from code, as the builder builds it: draft only, positions
 * dense where an order was asked for, nothing flushed.
 */
final class ContentManipulatorTest extends ControllerTestCase
{
    // ---------- sections ----------

    public function testASectionGetsItsLayoutsColumns(): void
    {
        $area = new ContentArea();

        $section = $this->manipulator()->addSection($area, Section::LAYOUT_THREE_COLS);

        $this->assertSame(Section::LAYOUT_THREE_COLS, $section->getLayout());
        $this->assertSame($area, $section->getContentArea());
        $this->assertSame(
            [['col-4', 0], ['col-4', 1], ['col-4', 2]],
            array_map(static fn (Column $c) => [$c->getPreset(), $c->getPreviewPosition()], $section->getColumns()->toArray()),
        );
        $this->assertContains($section, $this->persisted);
    }

    public function testSectionsAreAppendedInCallOrder(): void
    {
        $area = new ContentArea();
        $manipulator = $this->manipulator();

        $first = $manipulator->addSection($area);
        $second = $manipulator->addSection($area);

        $this->assertSame([0, 1], [$first->getPreviewPosition(), $second->getPreviewPosition()]);
    }

    public function testASectionCanBeInsertedAtAPosition(): void
    {
        $area = new ContentArea();
        $manipulator = $this->manipulator();
        $a = $manipulator->addSection($area);
        $b = $manipulator->addSection($area);

        $inserted = $manipulator->addSection($area, position: 1);

        $this->assertSame([0, 1, 2], [$a->getPreviewPosition(), $inserted->getPreviewPosition(), $b->getPreviewPosition()]);
    }

    public function testGivenSettingsAreMergedOverTheInitialOnes(): void
    {
        $manipulator = $this->manipulator(initialSettings: ['widthMode' => 'centered', 'styling' => ['padding' => 10, 'margin' => 5]]);

        $section = $manipulator->addSection(new ContentArea(), settings: ['styling' => ['padding' => 40]]);

        $this->assertSame(
            ['widthMode' => 'centered', 'styling' => ['padding' => 40, 'margin' => 5]],
            $section->getDraftSettings(),
        );
    }

    public function testALayoutDisplayingTabsWritesItsDisplay(): void
    {
        $layouts = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'tabs3' => ['label' => 'Tabs', 'columns' => [4, 4, 4], 'display' => 'tabs'],
        ]));

        $section = $this->manipulator(layouts: $layouts)->addSection(new ContentArea(), 'tabs3');

        $this->assertSame(['display' => 'tabs'], $section->getDraftSettings());
    }

    public function testAnUnknownLayoutIsRefused(): void
    {
        $this->expectExceptionObject(ContentManipulationException::unknownLayout('five_cols'));

        $this->manipulator()->addSection(new ContentArea(), 'five_cols');
    }

    public function testABuiltSectionIsPlacedLikeANewOne(): void
    {
        // A clone, a template or an import: built elsewhere, placed here.
        $area = $this->makeArea(1);
        $a = $this->makeSection($area, 10, 0);
        $b = $this->makeSection($area, 11, 1);
        $manipulator = $this->manipulator();

        $appended = $manipulator->insertSection($area, new Section());
        $between = $manipulator->insertSection($area, new Section(), 1);

        $this->assertSame([0, 1, 2, 3], [
            $a->getPreviewPosition(),
            $between->getPreviewPosition(),
            $b->getPreviewPosition(),
            $appended->getPreviewPosition(),
        ]);
        $this->assertSame($area, $between->getContentArea());
    }

    public function testWithoutAnEntityManagerNothingIsPersisted(): void
    {
        // The cascade from a managed area persists it at flush.
        $manipulator = new ContentManipulator(null, $this->makeRegistry());
        $this->makeEm();

        $section = $manipulator->addSection(new ContentArea());
        $manipulator->addBlock($section->getColumns()->first(), FakeBlockType::TYPE);

        $this->assertSame([], $this->persisted);
    }

    public function testMovingASectionReordersItsLiveSiblings(): void
    {
        $area = $this->makeArea(1);
        $a = $this->makeSection($area, 10, 0);
        $deleted = $this->makeSection($area, 11, 1);
        $deleted->setDeleted(true);
        $b = $this->makeSection($area, 12, 2);
        $c = $this->makeSection($area, 13, 3);

        $this->manipulator()->moveSection($c, 0);

        $this->assertSame([1, 2, 0], [$a->getPreviewPosition(), $b->getPreviewPosition(), $c->getPreviewPosition()]);
    }

    public function testDuplicatingASectionInsertsTheCopyAfterIt(): void
    {
        $area = $this->makeArea(1);
        $a = $this->makeSection($area, 10, 0);
        $b = $this->makeSection($area, 11, 1);
        $this->makeBlock($this->makeColumn($a, 100), 1000);

        $copy = $this->manipulator()->duplicateSection($a);

        $this->assertSame([0, 1, 2], [$a->getPreviewPosition(), $copy->getPreviewPosition(), $b->getPreviewPosition()]);
        $this->assertSame($area, $copy->getContentArea());
        $this->assertCount(1, $copy->getColumns()->first()->getBlocks());
    }

    public function testDeleteAndRestoreFlipTheDraftFlagOnly(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $manipulator = $this->manipulator();

        $manipulator->deleteSection($section);
        $this->assertTrue($section->isDeleted());
        $this->assertSame($area, $section->getContentArea());

        $manipulator->restoreSection($section);
        $this->assertFalse($section->isDeleted());
    }

    // ---------- columns ----------

    public function testAddingAColumnReSpansTheLiveOnes(): void
    {
        $section = $this->manipulator()->addSection(new ContentArea(), Section::LAYOUT_TWO_COLS);

        $column = $this->manipulator()->addColumn($section);

        $this->assertSame(2, $column->getPreviewPosition());
        $this->assertSame(['col-4', 'col-4', 'col-4'], array_map(
            static fn (Column $c) => $c->getPreset(),
            $section->getColumns()->toArray(),
        ));
    }

    public function testTheColumnLimitIsKept(): void
    {
        $section = $this->manipulator()->addSection(new ContentArea());
        for ($i = 1; $i < ContentManipulator::MAX_COLUMNS; ++$i) {
            $this->manipulator()->addColumn($section);
        }

        $this->expectExceptionObject(ContentManipulationException::tooManyColumns(ContentManipulator::MAX_COLUMNS));
        $this->manipulator()->addColumn($section);
    }

    public function testDeletingAColumnReSpansTheRest(): void
    {
        $section = $this->manipulator()->addSection(new ContentArea(), Section::LAYOUT_THREE_COLS);
        [$first, $second, $third] = $section->getColumns()->toArray();

        $this->manipulator()->deleteColumn($second);

        $this->assertTrue($second->isDeleted());
        $this->assertSame(['col-6', 'col-6'], [$first->getPreset(), $third->getPreset()]);
    }

    public function testTheLastColumnCannotBeDeleted(): void
    {
        $section = $this->manipulator()->addSection(new ContentArea());

        $this->expectExceptionObject(ContentManipulationException::lastColumn());
        $this->manipulator()->deleteColumn($section->getColumns()->first());
    }

    // ---------- blocks ----------

    public function testABlockMergesItsDataOverTheTypeDefaults(): void
    {
        $column = $this->manipulator()->addSection(new ContentArea())->getColumns()->first();

        $block = $this->manipulator()->addBlock($column, FakeBlockType::TYPE, ['extra' => 1]);

        $this->assertSame(['content' => 'default', 'extra' => 1], $block->getDraftData());
        $this->assertNull($block->getPublishedData());
        $this->assertSame($column, $block->getColumn());
        $this->assertContains($block, $this->persisted);
    }

    public function testBlocksAreAppendedOrInsertedAtAPosition(): void
    {
        $column = $this->manipulator()->addSection(new ContentArea())->getColumns()->first();
        $manipulator = $this->manipulator();

        $a = $manipulator->addBlock($column, FakeBlockType::TYPE);
        $b = $manipulator->addBlock($column, FakeBlockType::TYPE);
        $first = $manipulator->addBlock($column, FakeBlockType::TYPE, position: 0);

        $this->assertSame([0, 1, 2], [$first->getPreviewPosition(), $a->getPreviewPosition(), $b->getPreviewPosition()]);
    }

    public function testABuiltBlockIsPlacedLikeANewOne(): void
    {
        $column = $this->makeColumn($this->makeSection($this->makeArea(1), 10), 100);
        $existing = $this->makeBlock($column, 1000, 0);
        $block = new Block();
        $block->setType(FakeBlockType::TYPE);

        $this->manipulator()->insertBlock($column, $block, 0);

        $this->assertSame($column, $block->getColumn());
        $this->assertSame([0, 1], [$block->getPreviewPosition(), $existing->getPreviewPosition()]);
        $this->assertContains($block, $this->persisted);
    }

    public function testAnUnknownBlockTypeIsRefused(): void
    {
        $column = $this->manipulator()->addSection(new ContentArea())->getColumns()->first();

        $this->expectExceptionObject(ContentManipulationException::unknownBlockType('nope'));
        $this->manipulator()->addBlock($column, 'nope');
    }

    public function testMovingABlockAcrossColumnsKeepsItsPublishedColumn(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $from = $this->makeColumn($section, 100, 0);
        $to = $this->makeColumn($section, 101, 1);
        $moved = $this->makeBlock($from, 1000, 0);
        $moved->setDraftData(['content' => 'x']);
        $moved->publish();
        $stays = $this->makeBlock($from, 1001, 1);
        $existing = $this->makeBlock($to, 1002, 0);

        $this->manipulator()->moveBlock($moved, $to, 0);

        $this->assertSame($to, $moved->getColumn());
        $this->assertSame(100, $moved->getPublishedColumnId());
        $this->assertSame(0, $stays->getPreviewPosition());
        $this->assertSame([0, 1], [$moved->getPreviewPosition(), $existing->getPreviewPosition()]);
    }

    public function testABlockMovedWithoutPositionGoesLast(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $from = $this->makeColumn($section, 100, 0);
        $to = $this->makeColumn($section, 101, 1);
        $moved = $this->makeBlock($from, 1000);
        $this->makeBlock($to, 1001, 0);
        $this->makeBlock($to, 1002, 1);

        $this->manipulator()->moveBlock($moved, $to);

        $this->assertSame(2, $moved->getPreviewPosition());
    }

    public function testABlockCannotMoveToAnotherArea(): void
    {
        $moved = $this->makeBlock($this->makeColumn($this->makeSection($this->makeArea(1), 10), 100), 1000);
        $elsewhere = $this->makeColumn($this->makeSection($this->makeArea(2), 20), 200);

        $this->expectExceptionObject(ContentManipulationException::foreignTarget());
        $this->manipulator()->moveBlock($moved, $elsewhere);
    }

    public function testDuplicatingABlockTellsTheCloneObservers(): void
    {
        // So translations stored beside it follow, as for a section.
        $column = $this->makeColumn($this->makeSection($this->makeArea(1), 10), 100);
        $source = $this->makeBlock($column, 1000, 0);
        $source->setDraftData(['content' => 'hello']);
        $next = $this->makeBlock($column, 1001, 1);
        $observer = new class () implements BlockCloneObserverInterface {
            /** @var list<array{Block, Block}> */
            public array $seen = [];

            public function blockCloned(Block $source, Block $copy): void
            {
                $this->seen[] = [$source, $copy];
            }
        };

        $copy = $this->manipulator(observers: new BlockCloneObserverCollection([$observer]))->duplicateBlock($source);

        $this->assertSame([[$source, $copy]], $observer->seen);
        $this->assertSame(['content' => 'hello'], $copy->getDraftData());
        $this->assertSame([0, 1, 2], [$source->getPreviewPosition(), $copy->getPreviewPosition(), $next->getPreviewPosition()]);
    }

    // ---------- end to end ----------

    public function testWhatItBuildsPublishesAsIs(): void
    {
        $area = new ContentArea();
        $manipulator = $this->manipulator();
        $section = $manipulator->addSection($area, Section::LAYOUT_TWO_COLS);
        [$left, $right] = $section->getColumns()->toArray();
        $manipulator->addBlock($left, FakeBlockType::TYPE, ['content' => 'L']);
        $manipulator->addBlock($right, FakeBlockType::TYPE, ['content' => 'R']);

        (new ContentAreaPublisher($this->makeEm()))->publish($area);

        $this->assertNotNull($section->getPublishedAt());
        $this->assertSame(['content' => 'L'], $left->getBlocks()->first()->getPublishedData());
        $this->assertSame(['content' => 'R'], $right->getBlocks()->first()->getPublishedData());
    }

    /**
     * @param array<string, mixed> $initialSettings
     */
    private function manipulator(
        array $initialSettings = [],
        ?SectionLayoutRegistry $layouts = null,
        ?BlockCloneObserverCollection $observers = null,
    ): ContentManipulator {
        return new ContentManipulator(
            $this->makeEm(),
            $this->makeRegistry(),
            $layouts ?? new SectionLayoutRegistry(),
            $initialSettings,
            observers: $observers,
        );
    }
}
