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
     *
     * `$context` null means the same thing {@see PublishContext::everything()}
     * does, so `publish($area)` behaves exactly as it always has. The core
     * ignores the context: an area's own draft is a single state with no locale
     * dimension. It is read by satellite packages decorating this seam — with
     * `klehm/content-blocks-i18n` installed, it scopes which locales'
     * translations go live alongside the source.
     */
    public function publish(ContentArea $area, ?PublishContext $context = null): void;

    /**
     * Drop every unpublished change: an entity that was never published is a
     * brand-new addition and is removed entirely, everything else reverts to
     * its last published state.
     *
     * Same context semantics as {@see self::publish()}: null discards
     * everything, and a locale scope narrows which translations are reverted.
     */
    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void;
}
