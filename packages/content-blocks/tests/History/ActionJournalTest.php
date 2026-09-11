<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\History;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\BuilderSession;
use ContentBlocks\History\HistoryResult;
use ContentBlocks\History\JournalScope;
use ContentBlocks\History\StateApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The journal's policy: what a recorded entry contains, what undoing one puts
 * back, and the two cases where it refuses.
 */
final class ActionJournalTest extends TestCase
{
    /** @var list<object> */
    private array $managed = [];
    private int $nextId = 100;
    private InMemoryActionLogStore $store;
    private ActionJournal $journal;
    private ContentArea $area;

    protected function setUp(): void
    {
        $this->store = new InMemoryActionLogStore();
        $this->journal = $this->makeJournal($this->store);
        $this->area = $this->makeArea();
    }

    // ---------- recording ----------

    public function testAMutationThatMovedNothingRecordsNothing(): void
    {
        $this->record('section.move', fn () => null);

        $this->assertSame([], $this->store->entries);
    }

    public function testAnEntryOnlyCarriesTheEntitiesThatMoved(): void
    {
        $block = $this->blockAt(1, 0);
        $this->blockAt(1, 1);

        $this->record('block.delete', fn () => $block->setDeleted(true));

        $entry = $this->store->entries[0];
        $this->assertSame(
            [['t' => 'block', 'id' => $block->getId(), 'set' => ['deleted' => false]]],
            $entry->getUndoOps(),
        );
        $this->assertSame(
            [['t' => 'block', 'id' => $block->getId(), 'set' => ['deleted' => true]]],
            $entry->getRedoOps(),
        );
    }

    public function testASequenceOfActionsStacks(): void
    {
        $block = $this->blockAt(1, 0);

        $this->record('block.delete', fn () => $block->setDeleted(true));
        $this->record('block.restore', fn () => $block->setDeleted(false));

        $this->assertSame([1, 2], array_map(fn ($e) => $e->getSeq(), $this->store->entries));
    }

    // ---------- what the topbar's two buttons start out as ----------

    public function testAFreshAreaHasNothingToUndoOrRedo(): void
    {
        $this->assertSame(['canUndo' => false, 'canRedo' => false], $this->journal->state($this->area));
    }

    public function testARecordedActionIsUndoableAndNotYetRedoable(): void
    {
        $block = $this->blockAt(1, 0);
        $this->record('block.delete', fn () => $block->setDeleted(true));

        $this->assertSame(['canUndo' => true, 'canRedo' => false], $this->journal->state($this->area));
    }

    public function testUndoingMovesTheActionToTheRedoSide(): void
    {
        $block = $this->blockAt(1, 0);
        $this->record('block.delete', fn () => $block->setDeleted(true));
        $this->journal->undo($this->area);

        $this->assertSame(['canUndo' => false, 'canRedo' => true], $this->journal->state($this->area));
    }

    // ---------- the inverses ----------

    public function testUndoingACreationSoftDeletesIt(): void
    {
        $column = $this->column(1);
        $created = null;

        $this->record('block.create', function () use ($column, &$created) {
            $created = $this->persistBlock($column, 0);
        });

        $this->assertSame(HistoryResult::STATUS_OK, $this->journal->undo($this->area)->status);
        $this->assertTrue($created?->isDeleted());
    }

    public function testRedoingACreationBringsItBack(): void
    {
        $column = $this->column(1);
        $created = null;

        $this->record('block.create', function () use ($column, &$created) {
            $created = $this->persistBlock($column, 3);
        });
        $this->journal->undo($this->area);

        $this->assertSame(HistoryResult::STATUS_OK, $this->journal->redo($this->area)->status);
        $this->assertFalse($created?->isDeleted());
        $this->assertSame(3, $created?->getPreviewPosition());
    }

    public function testUndoingAReorderRestoresEveryPositionItTouched(): void
    {
        $first = $this->blockAt(1, 0);
        $second = $this->blockAt(1, 1);

        $this->record('block.move', function () use ($first, $second) {
            $first->setPreviewPosition(1);
            $second->setPreviewPosition(0);
        });
        $this->journal->undo($this->area);

        $this->assertSame(0, $first->getPreviewPosition());
        $this->assertSame(1, $second->getPreviewPosition());
    }

    /**
     * The published column is a separate note from the FK, and moveTo() would
     * recompute it — the recorded value has to win.
     */
    public function testUndoingACrossColumnMoveRestoresTheColumnAndItsPublishedTwin(): void
    {
        $source = $this->column(1);
        $target = $this->column(2);
        $block = $this->blockAt(1, 0);
        $block->setPublishedData(['title' => 'live']);

        $this->record('block.move', fn () => $block->moveTo($target));
        $this->assertSame($source->getId(), $block->getPublishedColumnId(), 'guard: the move noted its home');

        $this->journal->undo($this->area);

        $this->assertSame($source, $block->getColumn());
        $this->assertNull($block->getPublishedColumnId());
    }

    public function testUndoingASettingsSaveRestoresThePreviousPayload(): void
    {
        $section = $this->section(1);
        $section->setDraftSettings(['styling' => ['backgroundColor' => '#eb0540']]);

        $this->journal->record(
            $this->area,
            'section.settings',
            JournalScope::sectionSettings($section),
            fn () => $section->setDraftSettings(['styling' => ['backgroundColor' => '#000000']]),
        );
        $this->journal->undo($this->area);

        $this->assertSame(['styling' => ['backgroundColor' => '#eb0540']], $section->getDraftSettings());
    }

    // ---------- the stack ----------

    public function testUndoWalksBackwardsAndRedoForwards(): void
    {
        $block = $this->blockAt(1, 0);

        $this->record('a', fn () => $block->setPreviewPosition(1));
        $this->record('b', fn () => $block->setPreviewPosition(2));

        $this->assertSame('b', $this->journal->undo($this->area)->label);
        $this->assertSame(1, $block->getPreviewPosition());
        $this->assertSame('a', $this->journal->undo($this->area)->label);
        $this->assertSame(0, $block->getPreviewPosition());
        $this->assertSame('a', $this->journal->redo($this->area)->label);
        $this->assertSame(1, $block->getPreviewPosition());
    }

    public function testAFreshActionDropsTheRedoTail(): void
    {
        $block = $this->blockAt(1, 0);

        $this->record('a', fn () => $block->setPreviewPosition(1));
        $this->journal->undo($this->area);
        $this->record('b', fn () => $block->setPreviewPosition(5));

        $this->assertSame(HistoryResult::STATUS_NOTHING, $this->journal->redo($this->area)->status);
        $this->assertSame(['b'], array_map(fn ($e) => $e->getLabel(), $this->store->entries));
    }

    public function testUndoOnAnEmptyStackSaysSoRatherThanFailing(): void
    {
        $result = $this->journal->undo($this->area);

        $this->assertSame(HistoryResult::STATUS_NOTHING, $result->status);
        $this->assertFalse($result->canUndo);
        $this->assertFalse($result->canRedo);
    }

    // ---------- refusals ----------

    public function testAnEntryWhoseTargetMovedUnderItIsRefused(): void
    {
        $block = $this->blockAt(1, 0);
        $this->record('block.move', fn () => $block->setPreviewPosition(1));

        // Somebody else — another tab, another editor — moved it again.
        $block->setPreviewPosition(7);

        $this->assertSame(HistoryResult::STATUS_STALE, $this->journal->undo($this->area)->status);
        $this->assertSame(7, $block->getPreviewPosition(), 'a refusal changes nothing');
    }

    public function testAnEntryWhoseTargetIsGoneIsRefused(): void
    {
        $block = $this->blockAt(1, 0);
        $this->record('block.move', fn () => $block->setPreviewPosition(1));

        $this->managed = array_values(array_filter($this->managed, fn ($m) => $m !== $block));

        $this->assertSame(HistoryResult::STATUS_STALE, $this->journal->undo($this->area)->status);
    }

    public function testARowLeavingTheDraftEmptiesTheStackInsteadOfRecording(): void
    {
        $block = $this->blockAt(1, 0);
        $this->record('block.move', fn () => $block->setPreviewPosition(1));

        $this->record('block.vanish', function () use ($block) {
            $block->getColumn()?->removeBlock($block);
        });

        $this->assertSame([], $this->store->entries);
    }

    public function testWithoutASessionNothingIsRecordedAndUndoIsUnavailable(): void
    {
        $store = new InMemoryActionLogStore();
        $journal = new ActionJournal($store, new StateApplier($this->em()), new BuilderSession(new RequestStack()));
        $block = $this->blockAt(1, 0);

        $journal->record($this->area, 'block.move', JournalScope::structure(), fn () => $block->setPreviewPosition(1));

        $this->assertSame([], $store->entries);
        $this->assertSame(HistoryResult::STATUS_UNAVAILABLE, $journal->undo($this->area)->status);
    }

    public function testTwoSessionsKeepSeparateStacks(): void
    {
        $other = $this->makeJournal($this->store);
        $block = $this->blockAt(1, 0);

        $this->record('mine', fn () => $block->setPreviewPosition(1));

        $this->assertSame(HistoryResult::STATUS_NOTHING, $other->undo($this->area)->status);
        $this->assertSame(1, $block->getPreviewPosition());
    }

    // ---------- coalescing ----------

    public function testASecondSaveOfTheSameBlockMergesIntoTheFirst(): void
    {
        $block = $this->blockAt(1, 0);
        $block->setDraftData(['title' => '']);

        $this->recordData($block, ['title' => 'H']);
        $this->recordData($block, ['title' => 'He']);
        $this->recordData($block, ['title' => 'Hello']);

        $this->assertCount(1, $this->store->entries);

        $this->journal->undo($this->area);
        $this->assertSame(['title' => ''], $block->getDraftData(), 'the run reverts to where it started');
    }

    public function testADifferentBlockStartsItsOwnEntry(): void
    {
        $first = $this->blockAt(1, 0);
        $second = $this->blockAt(1, 1);

        $this->recordData($first, ['title' => 'a']);
        $this->recordData($second, ['title' => 'b']);

        $this->assertCount(2, $this->store->entries);
    }

    public function testARunEndsAfterThePause(): void
    {
        $block = $this->blockAt(1, 0);

        $this->recordData($block, ['title' => 'a']);
        $stale = $this->store->entries[0]
            ->getUpdatedAt()
            ->modify('-' . (ActionJournal::COALESCE_IDLE_SECONDS + 5) . ' seconds');
        $this->store->entries[0]->setUpdatedAt($stale);

        $this->recordData($block, ['title' => 'b']);

        $this->assertCount(2, $this->store->entries);
    }

    public function testARunNeverGrowsPastItsSpan(): void
    {
        $block = $this->blockAt(1, 0);

        $this->recordData($block, ['title' => 'a']);
        $old = $this->store->entries[0]
            ->getCreatedAt()
            ->modify('-' . (ActionJournal::COALESCE_SPAN_SECONDS + 5) . ' seconds');
        $this->store->entries[0]->setCreatedAt($old);

        $this->recordData($block, ['title' => 'b']);

        $this->assertCount(2, $this->store->entries);
    }

    // ---------- pruning ----------

    public function testForgetEmptiesTheStack(): void
    {
        $block = $this->blockAt(1, 0);
        $this->record('block.move', fn () => $block->setPreviewPosition(1));

        $this->journal->forget($this->area);

        $this->assertSame([], $this->store->entries);
        $this->assertSame(HistoryResult::STATUS_NOTHING, $this->journal->undo($this->area)->status);
    }

    // ---------- fixture ----------

    private function makeJournal(InMemoryActionLogStore $store): ActionJournal
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new ActionJournal($store, new StateApplier($this->em()), new BuilderSession($stack));
    }

    /** @param callable(): mixed $mutation */
    private function record(string $label, callable $mutation): void
    {
        $this->journal->record($this->area, $label, JournalScope::structure(), $mutation);
    }

    /** @param array<string, mixed> $data */
    private function recordData(Block $block, array $data): void
    {
        $this->journal->record(
            $this->area,
            'block.data',
            JournalScope::blockData($block),
            fn () => $block->setDraftData($data),
            'block.data:' . $block->getId(),
        );
    }

    /** An area with one section and two columns, all published and clean. */
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
        return $this->persistBlock($this->column($columnOrdinal), $previewPosition);
    }

    private function persistBlock(Column $column, int $previewPosition): Block
    {
        $block = new Block();
        $this->identify($block);
        $block->setType('text');
        $block->setPreviewPosition($previewPosition);
        $column->addBlock($block);
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
