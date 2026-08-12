<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes Publish and Discard cover translations too.
 *
 * ---- Why translations must not have their own buttons ----
 *
 * A translation is written against a specific source text. Publishing the two
 * independently allows the failure this feature exists to prevent: a French
 * heading live on the public site describing an English heading that is still
 * an unpublished draft. Riding the area's existing Publish means the source and
 * its translations always go live together, and Discard reverts both.
 *
 * (Per-locale publishing remains *possible* — the rows keep separate draft and
 * published payloads — but it is a deliberate later decision, not something a
 * host falls into by accident.)
 *
 * ---- Ordering ----
 *
 * Translations are promoted **before** delegating, because the inner publisher
 * both mutates and flushes. Rows belonging to soft-deleted blocks are removed
 * in the same pass: the database's `ON DELETE CASCADE` would clear them anyway,
 * but only after Doctrine had already tried to UPDATE rows it still believed
 * were live.
 *
 * A decorator rather than a fork of the publisher: the core aliases
 * {@see ContentAreaPublisherInterface}, so this composes with a host's own
 * decorator (audit trail, cache invalidation) instead of competing with it.
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

    public function publish(ContentArea $area): void
    {
        foreach ($this->repository->findForArea($area) as $row) {
            $block = $row->getBlock();

            if ($block === null || $block->isDeleted()) {
                $this->em->remove($row);

                continue;
            }

            $row->publish();

            // A row emptied by the editor ("this block has no German") has
            // served its purpose once published; keeping it would leave the
            // table growing with rows that mean nothing.
            if ($row->isEmpty()) {
                $this->em->remove($row);
            }
        }

        $this->store->reset();
        $this->inner->publish($area);
    }

    public function discardDraft(ContentArea $area): void
    {
        foreach ($this->repository->findForArea($area) as $row) {
            $block = $row->getBlock();

            // A block that was never published is a brand-new addition the
            // inner publisher is about to remove — same rule it applies to
            // blocks, applied to their translations.
            if ($block === null || $block->getPublishedData() === null) {
                $this->em->remove($row);

                continue;
            }

            $row->revertDraft();

            if ($row->isEmpty()) {
                $this->em->remove($row);
            }
        }

        $this->store->reset();
        $this->inner->discardDraft($area);
    }
}
