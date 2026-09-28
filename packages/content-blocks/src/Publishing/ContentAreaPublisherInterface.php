<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;

/**
 * Promotes a ContentArea's draft to published, or discards it. Both flush.
 * To follow a publish rather than change it, listen to its events.
 *
 * @see docs/internals/publishing.md#publish-and-discard-semantics
 * @see docs/guide/events.md
 */
interface ContentAreaPublisherInterface
{
    /**
     * Promote every draft change to its published slot; soft-deleted entities
     * go for good. `$context` null means {@see PublishContext::everything()}.
     *
     * @see docs/internals/publishing.md#publish-and-discard-semantics
     */
    public function publish(ContentArea $area, ?PublishContext $context = null): void;

    /**
     * Drop every unpublished change: a never-published entity goes entirely,
     * everything else reverts. Context as in {@see self::publish()}.
     */
    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void;
}
