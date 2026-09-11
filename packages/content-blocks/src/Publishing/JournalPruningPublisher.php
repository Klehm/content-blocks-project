<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;

/**
 * Empties the action history once the draft it describes is gone.
 *
 * @see docs/internals/history.md#publish-and-discard-end-the-stack
 */
final class JournalPruningPublisher implements ContentAreaPublisherInterface
{
    public function __construct(
        private readonly ContentAreaPublisherInterface $inner,
        private readonly ActionJournal $journal,
    ) {
    }

    public function publish(ContentArea $area, ?PublishContext $context = null): void
    {
        // After, not before: an inner publish that throws leaves the stack
        // describing a draft that is still there.
        $this->inner->publish($area, $context);
        $this->journal->forget($area);
    }

    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
    {
        $this->inner->discardDraft($area, $context);
        $this->journal->forget($area);
    }
}
