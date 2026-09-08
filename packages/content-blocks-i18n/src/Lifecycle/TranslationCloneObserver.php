<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\Section\BlockCloneObserverInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carries a block's translations onto its copies — the cost the side-table
 * schema pays for its queryability. Everything lands in the copy's draft.
 *
 * @see docs/internals/i18n.md#why-a-side-table-not-an-envelope-in-blockdata
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
