<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Asset;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Asset\JsonPayload;
use ContentBlocks\I18n\Entity\BlockTranslation;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Asset references held by translated values — a file no block's data mentions
 * anywhere. Both slots are read, as in the core provider.
 *
 * @see docs/internals/i18n.md#translated-values-hold-asset-references-too
 */
final class TranslationAssetReferenceProvider implements AssetReferenceProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetReferenceCollector $collector,
    ) {
    }

    /**
     * @return iterable<string>
     */
    public function referencedAssetPaths(): iterable
    {
        $rows = $this->em
            ->createQuery(
                'SELECT t.publishedValues AS published, t.draftValues AS draft FROM ' . BlockTranslation::class . ' t',
            )
            ->toIterable([], AbstractQuery::HYDRATE_SCALAR);

        foreach ($rows as $row) {
            foreach (['published', 'draft'] as $slot) {
                // Raw JSON string under scalar hydration — see JsonPayload.
                $values = JsonPayload::decode(\is_array($row) ? ($row[$slot] ?? null) : null);
                if ($values === []) {
                    continue;
                }

                yield from $this->collector->collect($values);
            }
        }
    }
}
