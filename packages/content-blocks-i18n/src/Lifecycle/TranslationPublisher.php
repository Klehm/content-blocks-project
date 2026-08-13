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
 * ---- Per-locale scoping ----
 *
 * That default is what a bare `publish($area)` still does. A caller that passes
 * a {@see PublishContext} narrows which locales ride along: `withLocales('fr')`
 * takes French live and leaves German on its published values, `sourceOnly()`
 * publishes the source and holds every translation back.
 *
 * The dangerous direction stays inexpressible by construction — the context
 * scopes translations, never the area's own draft, so a locale can be held back
 * but never pushed ahead of the source text it was written against. No UI
 * exposes this yet; it is API surface, frozen with 1.0 because the alternative
 * was widening a published signature afterwards.
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

    public function publish(ContentArea $area, ?PublishContext $context = null): void
    {
        foreach ($this->repository->findForArea($area) as $row) {
            $block = $row->getBlock();

            if ($block === null || $block->isDeleted()) {
                $this->em->remove($row);

                continue;
            }

            // Out of scope: this locale keeps whatever it has published, and
            // its draft survives for a later publish. Removing an orphaned row
            // above is unconditional either way — the block is gone, so there
            // is no locale left to hold back.
            if ($context !== null && !$context->coversLocale($row->getLocale())) {
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
        $this->inner->publish($area, $context);
    }

    public function discardDraft(ContentArea $area, ?PublishContext $context = null): void
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
