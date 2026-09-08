<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\PublishContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes Publish and Discard cover translations too, as a decorator so it
 * composes with a host's own rather than competing with it.
 *
 * @see docs/internals/publishing.md#the-invariant-the-shape-enforces
 */
final class TranslationPublisher implements ContentAreaPublisherInterface
{
    public function __construct(
        private readonly ContentAreaPublisherInterface $inner,
        private readonly BlockTranslationRepository $repository,
        private readonly TranslationStore $store,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function publish(ContentArea $area, ?PublishContext $context = null): void
    {
        foreach ($this->repository->findForArea($area) as $row) {
            $block = $row->getBlock();

            if ($block === null || $block->isDeleted()) {
                $this->em->remove($row);

                continue;
            }

            // Out of scope: this locale keeps what it has published and its
            // draft survives. Orphan removal above is unconditional.
            if ($context !== null && !$context->coversLocale($row->getLocale())) {
                continue;
            }

            $row->publish();

            // An emptied row has served its purpose once published; keeping
            // it would grow the table with rows that mean nothing.
            if ($row->isEmpty()) {
                $this->em->remove($row);
            }
        }

        $this->store->reset();
        $this->inner->publish($area, $context);
    }

    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
    {
        foreach ($this->repository->findForArea($area) as $row) {
            $block = $row->getBlock();

            // A never-published block is about to be removed by the inner
            // publisher; same rule, applied to its translations.
            if ($block === null || $block->getPublishedData() === null) {
                $this->em->remove($row);

                continue;
            }

            // Same scoping rule as publish: the area's own draft always
            // goes, a locale left out of the scope keeps its draft.
            if ($context !== null && !$context->coversLocale($row->getLocale())) {
                continue;
            }

            $row->revertDraft();

            if ($row->isEmpty()) {
                $this->em->remove($row);
            }
        }

        $this->store->reset();
        $this->inner->discardDraft($area, $context);
    }
}
