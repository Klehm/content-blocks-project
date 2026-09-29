<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;

/**
 * Which locales hold a translation Publish would put live — what the builder's
 * Publish button and the workbench's per-language one read.
 *
 * @see docs/internals/i18n.md#publishing-one-language
 */
final class TranslationDrafts
{
    public function __construct(
        private readonly BlockTranslationRepository $repository,
        private readonly ?ColumnTranslationRepository $columnRepository = null,
    ) {
    }

    public function any(ContentArea $area): bool
    {
        return $this->pendingLocales($area) !== [];
    }

    public function hasPending(ContentArea $area, string $locale): bool
    {
        return \in_array($locale, $this->pendingLocales($area, $locale), true);
    }

    /**
     * @return list<string>
     */
    public function pendingLocales(ContentArea $area, ?string $locale = null): array
    {
        if ($area->getId() === null) {
            return [];
        }

        $rows = [
            ...$this->repository->findForArea($area, $locale),
            ...$this->columnRepository?->findForArea($area, $locale) ?? [],
        ];

        $out = [];
        foreach ($rows as $row) {
            if (self::isPending($row)) {
                $out[$row->getLocale()] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * A draft equal to what is live — typed, then undone — is not a change:
     * the Publish button would light up for nothing.
     */
    private static function isPending(BlockTranslation|ColumnTranslation $row): bool
    {
        if (!$row->hasUnpublishedChanges()) {
            return false;
        }

        return $row->getDraftValues() != ($row->getPublishedValues() ?? [])
            || $row->getDraftDigests() != ($row->getPublishedDigests() ?? []);
    }
}
