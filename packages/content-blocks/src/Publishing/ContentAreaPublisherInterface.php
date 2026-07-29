<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;

/**
 * Promotes a ContentArea's draft state to published, or discards it.
 *
 * Override seam: the bundle aliases this to the shipped {@see ContentAreaPublisher}.
 * A host that needs to hook the lifecycle (audit trail, cache invalidation,
 * webhook…) re-aliases the interface to its own implementation — typically
 * decorating the default via `#[AsDecorator(ContentAreaPublisherInterface::class)]`.
 *
 * Both methods flush the EntityManager: they are the two terminal operations of
 * the draft lifecycle, so committing is part of what they mean. The services
 * that *build* rather than commit ({@see SectionClonerInterface},
 * {@see ContentAreaImporterInterface}, {@see SectionTemplateInstantiatorInterface})
 * leave the flush to their caller.
 */
interface ContentAreaPublisherInterface
{
    /**
     * Promote every draft change on the area — positions, section settings,
     * block data — to its published slot. Entities flagged as soft-deleted are
     * removed for good, their descendants following through Doctrine's cascade.
     */
    public function publish(ContentArea $area): void;

    /**
     * Drop every unpublished change: an entity that was never published is a
     * brand-new addition and is removed entirely, everything else reverts to
     * its last published state.
     */
    public function discardDraft(ContentArea $area): void;
}
