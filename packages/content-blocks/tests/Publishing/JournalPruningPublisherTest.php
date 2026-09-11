<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Publishing;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\BuilderSession;
use ContentBlocks\History\HistoryResult;
use ContentBlocks\History\JournalScope;
use ContentBlocks\History\StateApplier;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\JournalPruningPublisher;
use ContentBlocks\Publishing\PublishContext;
use ContentBlocks\Tests\History\InMemoryActionLogStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Publish removes soft-deleted rows and discard reverts every draft field, so
 * an entry recorded before either one describes a draft that is gone.
 */
final class JournalPruningPublisherTest extends TestCase
{
    private InMemoryActionLogStore $store;
    private ActionJournal $journal;
    private ContentArea $area;

    protected function setUp(): void
    {
        $this->store = new InMemoryActionLogStore();
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $this->journal = new ActionJournal(
            $this->store,
            new StateApplier($this->createMock(EntityManagerInterface::class)),
            new BuilderSession($stack),
        );

        $this->area = new ContentArea();
        (new \ReflectionProperty(ContentArea::class, 'id'))->setValue($this->area, 1);
    }

    public function testPublishEmptiesTheStack(): void
    {
        $publisher = new JournalPruningPublisher($this->innerSpy(), $this->journal);
        $this->recordSomething();

        $publisher->publish($this->area);

        $this->assertSame([], $this->store->entries);
        $this->assertSame(HistoryResult::STATUS_NOTHING, $this->journal->undo($this->area)->status);
    }

    public function testDiscardEmptiesTheStack(): void
    {
        $publisher = new JournalPruningPublisher($this->innerSpy(), $this->journal);
        $this->recordSomething();

        $publisher->discardDraft($this->area);

        $this->assertSame([], $this->store->entries);
    }

    /** The decoration is transparent: the inner publisher still runs. */
    public function testTheInnerPublisherIsCalledWithItsContext(): void
    {
        $context = PublishContext::everything();
        $inner = $this->createMock(ContentAreaPublisherInterface::class);
        $inner->expects($this->once())->method('publish')->with($this->area, $context);

        (new JournalPruningPublisher($inner, $this->journal))->publish($this->area, $context);
    }

    /** A failed publish leaves a draft the stack still describes correctly. */
    public function testAThrowingInnerPublisherLeavesTheStackAlone(): void
    {
        $inner = $this->createMock(ContentAreaPublisherInterface::class);
        $inner->method('publish')->willThrowException(new \RuntimeException('boom'));
        $this->recordSomething();

        try {
            (new JournalPruningPublisher($inner, $this->journal))->publish($this->area);
            $this->fail('expected the inner failure to propagate');
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, $this->store->entries);
    }

    private function innerSpy(): ContentAreaPublisherInterface
    {
        return $this->createMock(ContentAreaPublisherInterface::class);
    }

    private function recordSomething(): void
    {
        $section = new \ContentBlocks\Entity\Section();
        (new \ReflectionProperty(\ContentBlocks\Entity\Section::class, 'id'))->setValue($section, 2);
        $this->area->addSection($section);

        $this->journal->record(
            $this->area,
            'section.delete',
            JournalScope::structure(),
            fn () => $section->setDeleted(true),
        );
    }
}
