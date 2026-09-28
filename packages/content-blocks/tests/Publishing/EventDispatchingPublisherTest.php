<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Publishing;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Event\ActionRefusedException;
use ContentBlocks\Event\AfterContentAreaDiscardEvent;
use ContentBlocks\Event\AfterContentAreaPublishEvent;
use ContentBlocks\Event\BeforeContentAreaDiscardEvent;
use ContentBlocks\Event\BeforeContentAreaPublishEvent;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\EventDispatchingPublisher;
use ContentBlocks\Publishing\PublishContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class EventDispatchingPublisherTest extends TestCase
{
    private const EVENTS = [
        BeforeContentAreaPublishEvent::class,
        AfterContentAreaPublishEvent::class,
        BeforeContentAreaDiscardEvent::class,
        AfterContentAreaDiscardEvent::class,
    ];

    /** @var list<string> */
    private array $log = [];
    private EventDispatcher $events;
    private ContentArea $area;

    protected function setUp(): void
    {
        $this->events = new EventDispatcher();
        $this->area = new ContentArea();
        foreach (self::EVENTS as $name) {
            $this->events->addListener($name, function (object $event) use ($name): void {
                $this->log[] = $name;
                $this->assertSame($this->area, $event->area);
            });
        }
    }

    public function testPublishIsBracketedByItsTwoEvents(): void
    {
        $publisher = new EventDispatchingPublisher($this->logging(), $this->events);

        $publisher->publish($this->area);

        $this->assertSame([
            BeforeContentAreaPublishEvent::class,
            'inner.publish',
            AfterContentAreaPublishEvent::class,
        ], $this->log);
    }

    public function testDiscardIsBracketedByItsTwoEvents(): void
    {
        $publisher = new EventDispatchingPublisher($this->logging(), $this->events);

        $publisher->discardDraft($this->area);

        $this->assertSame([
            BeforeContentAreaDiscardEvent::class,
            'inner.discard',
            AfterContentAreaDiscardEvent::class,
        ], $this->log);
    }

    public function testARefusedPublishThrowsAndWritesNothing(): void
    {
        $this->events->addListener(
            BeforeContentAreaPublishEvent::class,
            fn (BeforeContentAreaPublishEvent $e) => $e->refuse('The page needs a title.'),
        );
        $publisher = new EventDispatchingPublisher($this->logging(), $this->events);

        try {
            $publisher->publish($this->area);
            $this->fail('A refused publish must throw.');
        } catch (ActionRefusedException $e) {
            $this->assertSame(['The page needs a title.'], $e->reasons);
            $this->assertSame('The page needs a title.', $e->getMessage());
        }

        $this->assertSame([BeforeContentAreaPublishEvent::class], $this->log);
    }

    public function testARefusedDiscardThrowsAndWritesNothing(): void
    {
        $this->events->addListener(
            BeforeContentAreaDiscardEvent::class,
            fn (BeforeContentAreaDiscardEvent $e) => $e->refuse('Locked.'),
        );
        $publisher = new EventDispatchingPublisher($this->logging(), $this->events);

        $this->expectException(ActionRefusedException::class);
        try {
            $publisher->discardDraft($this->area);
        } finally {
            $this->assertSame([BeforeContentAreaDiscardEvent::class], $this->log);
        }
    }

    /** Every listener still runs after a refusal, and each reason is kept. */
    public function testEveryRefusalReasonIsCollected(): void
    {
        foreach (['First.', 'Second.'] as $reason) {
            $this->events->addListener(
                BeforeContentAreaPublishEvent::class,
                fn (BeforeContentAreaPublishEvent $e) => $e->refuse($reason),
            );
        }
        $publisher = new EventDispatchingPublisher($this->logging(), $this->events);

        try {
            $publisher->publish($this->area);
            $this->fail('A refused publish must throw.');
        } catch (ActionRefusedException $e) {
            $this->assertSame(['First.', 'Second.'], $e->reasons);
            $this->assertSame('First. Second.', $e->getMessage());
        }
    }

    /** A null context reaches listeners as what it means: everything. */
    public function testANullContextIsHandedOnAsEverything(): void
    {
        $seen = [];
        foreach ([BeforeContentAreaPublishEvent::class, AfterContentAreaPublishEvent::class] as $name) {
            $this->events->addListener($name, function (object $e) use (&$seen): void {
                $seen[] = $e->context;
            });
        }

        (new EventDispatchingPublisher($this->logging(), $this->events))->publish($this->area);

        $this->assertCount(2, $seen);
        $this->assertNull($seen[0]->locales);
        $this->assertSame($seen[0], $seen[1]);
    }

    public function testAnExplicitContextIsPassedToTheInnerAndTheEvents(): void
    {
        $context = PublishContext::withLocales('fr');
        $inner = $this->createMock(ContentAreaPublisherInterface::class);
        $inner->expects($this->once())->method('discardDraft')->with($this->area, $context);
        $seen = [];
        foreach ([BeforeContentAreaDiscardEvent::class, AfterContentAreaDiscardEvent::class] as $name) {
            $this->events->addListener($name, function (object $e) use (&$seen): void {
                $seen[] = $e->context;
            });
        }

        (new EventDispatchingPublisher($inner, $this->events))->discardDraft($this->area, $context);

        $this->assertSame([$context, $context], $seen);
    }

    /** A publish that failed changed nothing an "after" listener expects. */
    public function testNoAfterEventWhenTheInnerPublisherThrows(): void
    {
        $inner = $this->createMock(ContentAreaPublisherInterface::class);
        $inner->method('publish')->willThrowException(new \RuntimeException('db down'));
        $publisher = new EventDispatchingPublisher($inner, $this->events);

        try {
            $publisher->publish($this->area);
            $this->fail('The exception should propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([BeforeContentAreaPublishEvent::class], $this->log);
    }

    private function logging(): ContentAreaPublisherInterface
    {
        $log = &$this->log;

        return new class ($log) implements ContentAreaPublisherInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function publish(ContentArea $area, ?PublishContext $context = null): void
            {
                $this->log[] = 'inner.publish';
            }

            public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
            {
                $this->log[] = 'inner.discard';
            }
        };
    }
}
