<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\History;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\History\SidebarOutcome;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Whether the open sidebar outlives an undo. Closing it every time cost the
 * editor their place; never closing leaves a form over a deleted row.
 */
final class SidebarOutcomeTest extends TestCase
{
    /** @var list<object> */
    private array $managed = [];
    private int $nextId = 100;
    private ContentArea $area;
    private SidebarOutcome $outcome;

    protected function setUp(): void
    {
        $this->area = $this->makeArea();
        $this->outcome = new SidebarOutcome($this->em());
    }

    public function testNothingOpenIsNothingToDecide(): void
    {
        $this->assertSame(SidebarOutcome::KEEP, $this->outcome->decide([], null, null));
    }

    public function testAStepSomewhereElseLeavesTheOpenBlockAlone(): void
    {
        $open = $this->blockAt(1, 0);
        $other = $this->blockAt(1, 1);

        $verdict = $this->outcome->decide(
            [$this->op('block', $other, ['data' => ['title' => 'x']])],
            'block',
            (int) $open->getId(),
        );

        $this->assertSame(SidebarOutcome::KEEP, $verdict);
    }

    /** Moving a block changes no field its form renders. */
    public function testAPositionOnlyStepDoesNotRepaintTheForm(): void
    {
        $open = $this->blockAt(1, 0);

        $verdict = $this->outcome->decide(
            [$this->op('block', $open, ['previewPosition' => 3])],
            'block',
            (int) $open->getId(),
        );

        $this->assertSame(SidebarOutcome::KEEP, $verdict);
    }

    public function testTheOpenBlocksOwnDataMovingRepaintsIt(): void
    {
        $open = $this->blockAt(1, 0);

        $verdict = $this->outcome->decide(
            [$this->op('block', $open, ['data' => ['title' => 'was']])],
            'block',
            (int) $open->getId(),
        );

        $this->assertSame(SidebarOutcome::RELOAD, $verdict);
    }

    public function testAnUndoneCreationClosesTheFormOverIt(): void
    {
        $open = $this->blockAt(1, 0);
        $open->setDeleted(true);

        $verdict = $this->outcome->decide(
            [$this->op('block', $open, ['deleted' => true])],
            'block',
            (int) $open->getId(),
        );

        $this->assertSame(SidebarOutcome::CLOSE, $verdict);
    }

    public function testABlockWhoseIdIsGoneClosesRatherThanKeeps(): void
    {
        $this->assertSame(SidebarOutcome::CLOSE, $this->outcome->decide([], 'block', 9999));
    }

    /**
     * No op names the block — a section delete marks the section — but the
     * block is off the page all the same.
     */
    public function testABlockUnderARestoredSectionDeleteCloses(): void
    {
        $open = $this->blockAt(1, 0);
        $this->section(1)->setDeleted(true);

        $verdict = $this->outcome->decide(
            [$this->op('section', $this->section(1), ['deleted' => true])],
            'block',
            (int) $open->getId(),
        );

        $this->assertSame(SidebarOutcome::CLOSE, $verdict);
    }

    public function testTheOpenSectionsSettingsMovingRepaintsIt(): void
    {
        $section = $this->section(1);

        $verdict = $this->outcome->decide(
            [$this->op('section', $section, ['settings' => ['styling' => []]])],
            'section',
            (int) $section->getId(),
        );

        $this->assertSame(SidebarOutcome::RELOAD, $verdict);
    }

    /** `columnWidths` is one of the section form's own fields. */
    public function testAColumnPresetMovingRepaintsItsSectionsForm(): void
    {
        $section = $this->section(1);

        $verdict = $this->outcome->decide(
            [$this->op('column', $this->column(2), ['preset' => 'col-4'])],
            'section',
            (int) $section->getId(),
        );

        $this->assertSame(SidebarOutcome::RELOAD, $verdict);
    }

    public function testAnotherSectionsColumnLeavesThisFormAlone(): void
    {
        $section = $this->section(1);
        $foreign = new Column();
        $this->identify($foreign);
        $this->managed[] = $foreign;

        $verdict = $this->outcome->decide(
            [$this->op('column', $foreign, ['preset' => 'col-4'])],
            'section',
            (int) $section->getId(),
        );

        $this->assertSame(SidebarOutcome::KEEP, $verdict);
    }

    public function testADeletedSectionClosesItsOwnForm(): void
    {
        $section = $this->section(1);
        $section->setDeleted(true);

        $verdict = $this->outcome->decide(
            [$this->op('section', $section, ['deleted' => true])],
            'section',
            (int) $section->getId(),
        );

        $this->assertSame(SidebarOutcome::CLOSE, $verdict);
    }

    // ---------- fixtures ----------

    /**
     * @param array<string, mixed> $set
     *
     * @return array<string, mixed>
     */
    private function op(string $type, Block|Column|Section $entity, array $set): array
    {
        return ['t' => $type, 'id' => $entity->getId(), 'set' => $set];
    }

    /** An area with one section and two columns, all clean. */
    private function makeArea(): ContentArea
    {
        $area = new ContentArea();
        $this->identify($area);
        $this->managed[] = $area;

        $section = new Section();
        $this->identify($section);
        $area->addSection($section);
        $this->managed[] = $section;

        foreach ([1, 2] as $i) {
            $column = new Column();
            $this->identify($column);
            $column->setPreviewPosition($i - 1);
            $section->addColumn($column);
            $this->managed[] = $column;
        }

        return $area;
    }

    private function section(int $ordinal): Section
    {
        return $this->area->getSections()->toArray()[$ordinal - 1];
    }

    private function column(int $ordinal): Column
    {
        return $this->section(1)->getColumns()->toArray()[$ordinal - 1];
    }

    private function blockAt(int $columnOrdinal, int $previewPosition): Block
    {
        $block = new Block();
        $this->identify($block);
        $block->setType('text');
        $block->setPreviewPosition($previewPosition);
        $this->column($columnOrdinal)->addBlock($block);
        $this->managed[] = $block;

        return $block;
    }

    private function identify(object $entity): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, ++$this->nextId);
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            foreach ($this->managed as $entity) {
                if ($entity instanceof $class && $entity->getId() === $id) {
                    return $entity;
                }
            }

            return null;
        });

        return $em;
    }
}
