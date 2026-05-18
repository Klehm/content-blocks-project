<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Doctrine;

use ContentBlocks\Doctrine\ContentAreaTouchListener;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class ContentAreaTouchListenerTest extends TestCase
{
    /** @var list<object> */
    private array $recomputed = [];
    /** @var list<int> */
    private array $deletedIds = [];

    public function testTouchesAreaWhenChildBlockIsUpdated(): void
    {
        $area = new ContentArea();
        $section = (new Section())->setLayout(Section::LAYOUT_FULL);
        $area->addSection($section);
        $column = new Column();
        $section->addColumn($column);
        $block = (new Block())->setType('text');
        $column->addBlock($block);

        $args = $this->makeArgs(inserts: [], updates: [$block], deletes: []);

        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertInstanceOf(\DateTimeImmutable::class, $area->getUpdatedAt());
        $this->assertSame([$area], $this->recomputed);
    }

    public function testTouchesAreaWhenChildSectionIsInserted(): void
    {
        $area = new ContentArea();
        $section = (new Section())->setLayout(Section::LAYOUT_FULL);
        $area->addSection($section);

        $args = $this->makeArgs(inserts: [$section], updates: [], deletes: []);
        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertInstanceOf(\DateTimeImmutable::class, $area->getUpdatedAt());
        $this->assertSame([$area], $this->recomputed);
    }

    public function testTouchesAreaWhenChildColumnIsDeleted(): void
    {
        $area = new ContentArea();
        $section = (new Section())->setLayout(Section::LAYOUT_FULL);
        $area->addSection($section);
        $column = new Column();
        $section->addColumn($column);

        $args = $this->makeArgs(inserts: [], updates: [], deletes: [$column]);
        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertInstanceOf(\DateTimeImmutable::class, $area->getUpdatedAt());
        $this->assertSame([$area], $this->recomputed);
    }

    public function testIgnoresUnrelatedEntities(): void
    {
        $stranger = new \stdClass();
        $args = $this->makeArgs(inserts: [], updates: [$stranger], deletes: []);

        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertSame([], $this->recomputed);
    }

    public function testSkipsAreaScheduledForDeletion(): void
    {
        $area = new ContentArea();
        $section = (new Section())->setLayout(Section::LAYOUT_FULL);
        $area->addSection($section);

        $this->deletedIds = [\spl_object_id($area)];
        $args = $this->makeArgs(inserts: [], updates: [], deletes: [$area, $section]);

        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertNull($area->getUpdatedAt());
        $this->assertSame([], $this->recomputed);
    }

    public function testTouchesEachAreaAcrossMultipleChildren(): void
    {
        $areaA = new ContentArea();
        $sectionA = (new Section())->setLayout(Section::LAYOUT_FULL);
        $areaA->addSection($sectionA);

        $areaB = new ContentArea();
        $sectionB = (new Section())->setLayout(Section::LAYOUT_FULL);
        $areaB->addSection($sectionB);

        $args = $this->makeArgs(inserts: [], updates: [$sectionA, $sectionB], deletes: []);
        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertInstanceOf(\DateTimeImmutable::class, $areaA->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $areaB->getUpdatedAt());
        $this->assertCount(2, $this->recomputed);
    }

    public function testCollapsesDuplicateMentionsOfTheSameArea(): void
    {
        $area = new ContentArea();
        $section = (new Section())->setLayout(Section::LAYOUT_FULL);
        $area->addSection($section);
        $column = new Column();
        $section->addColumn($column);
        $block = (new Block())->setType('text');
        $column->addBlock($block);

        // Updating block + column + section in the same flush should still
        // touch the area exactly once.
        $args = $this->makeArgs(inserts: [], updates: [$block, $column, $section], deletes: []);
        (new ContentAreaTouchListener())->onFlush($args);

        $this->assertSame([$area], $this->recomputed);
    }

    /**
     * @param array<object> $inserts
     * @param array<object> $updates
     * @param array<object> $deletes
     */
    private function makeArgs(array $inserts, array $updates, array $deletes): OnFlushEventArgs
    {
        $this->recomputed = [];

        $meta = $this->createMock(ClassMetadata::class);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($inserts);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getScheduledEntityDeletions')->willReturn($deletes);
        $uow->method('isScheduledForDelete')->willReturnCallback(
            fn (object $e) => \in_array(\spl_object_id($e), $this->deletedIds, true),
        );
        $uow->method('recomputeSingleEntityChangeSet')->willReturnCallback(
            function ($_m, $entity): void {
                $this->recomputed[] = $entity;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($meta);

        return new OnFlushEventArgs($em);
    }
}
