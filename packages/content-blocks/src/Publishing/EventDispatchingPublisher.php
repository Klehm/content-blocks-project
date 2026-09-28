<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Event\ActionRefusedException;
use ContentBlocks\Event\AfterContentAreaDiscardEvent;
use ContentBlocks\Event\AfterContentAreaPublishEvent;
use ContentBlocks\Event\BeforeContentAreaDiscardEvent;
use ContentBlocks\Event\BeforeContentAreaPublishEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Outermost decorator of the publisher seam: "before" runs ahead of every
 * decorator, "after" once the whole chain, translations included, committed.
 *
 * @see docs/internals/publishing.md#publish-events
 */
final class EventDispatchingPublisher implements ContentAreaPublisherInterface
{
    public function __construct(
        private readonly ContentAreaPublisherInterface $inner,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** @throws ActionRefusedException when a listener refused the publish */
    public function publish(ContentArea $area, ?PublishContext $context = null): void
    {
        $context ??= PublishContext::everything();
        $before = $this->events->dispatch(new BeforeContentAreaPublishEvent($area, $context));
        if ($before->isRefused()) {
            throw ActionRefusedException::from($before);
        }

        $this->inner->publish($area, $context);
        $this->events->dispatch(new AfterContentAreaPublishEvent($area, $context));
    }

    /** @throws ActionRefusedException when a listener refused the discard */
    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
    {
        $context ??= PublishContext::everything();
        $before = $this->events->dispatch(new BeforeContentAreaDiscardEvent($area, $context));
        if ($before->isRefused()) {
            throw ActionRefusedException::from($before);
        }

        $this->inner->discardDraft($area, $context);
        $this->events->dispatch(new AfterContentAreaDiscardEvent($area, $context));
    }
}
