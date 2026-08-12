<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\Section\BlockCloneObserverInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carries a block's translations onto its copies.
 *
 * This is the cost the side-table schema pays for its queryability: values that
 * lived inside `Block.data` would ride along with every duplicate for free.
 * Here every duplication flow — section duplicate, paste, replace-content,
 * "save as model" — has to be taught, which is exactly what the core's
 * {@see BlockCloneObserverInterface} seam makes possible without any of those
 * flows knowing this package exists.
 *
 * ---- Everything is copied into the draft ----
 *
 * A cloned section is born as an unpublished change, and its translations must
 * match: the copy's draft holds what the source showed the editor (draft-or-
 * published, the cloner's own rule), and nothing lands in the copy's published
 * slot. Publish then commits section and translations together; Discard drops
 * both.
 *
 * ---- Ids ----
 *
 * `$copy` has no id yet — it is persisted by the caller after the walk. So the
 * new rows are persisted here against the *object*, and Doctrine resolves the
 * foreign key when the caller flushes. Rows are never flushed here, matching
 * the cloner's contract that building and committing are separate.
 *
 * Collection entry `_id`s are copied verbatim by the cloner, which is what
 * makes the value keys valid against the copy: `items[9f2c1a].label` addresses
 * the same card in both.
 */
final class TranslationCloneObserver implements BlockCloneObserverInterface
{
    public function __construct(
        private readonly BlockTranslationRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function blockCloned(Block $source, Block $copy): void
    {
        $sourceId = $source->getId();

        if ($sourceId === null) {
            // A source that was never persisted has no rows to copy — a clone
            // of a clone inside one unit of work.
            return;
        }

        foreach ($this->repository->findForBlockIds([$sourceId]) as $row) {
            $values = $row->getEffectiveValues();

            if ($values === []) {
                continue;
            }

            $translation = new BlockTranslation($copy, $row->getLocale());
            $translation->setDraftPayload($values, $row->getEffectiveDigests());

            $this->em->persist($translation);
        }
    }
}
