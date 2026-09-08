<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Entity\SectionTemplate;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Asset references held by the section-template library, whose payload keeps
 * them as plain storage paths.
 *
 * @see docs/internals/assets.md#nothing-deletes-on-delete
 */
final class SectionTemplateAssetReferenceProvider implements AssetReferenceProviderInterface
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
            ->createQuery('SELECT t.payload AS payload FROM ' . SectionTemplate::class . ' t')
            ->toIterable([], AbstractQuery::HYDRATE_SCALAR);

        foreach ($rows as $row) {
            // Raw JSON string under scalar hydration — see JsonPayload.
            $payload = JsonPayload::decode(\is_array($row) ? ($row['payload'] ?? null) : null);
            if ($payload === []) {
                continue;
            }

            yield from $this->collector->collect($payload);
        }
    }
}
