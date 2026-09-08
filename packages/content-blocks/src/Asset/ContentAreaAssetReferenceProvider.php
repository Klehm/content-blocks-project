<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Asset references held by content itself: every block's data and every
 * section's settings.
 *
 * Three deliberate choices, each of which would cost a live page if reversed:
 *
 * - **Both twins.** `publishedData` *and* `draftData` count. A file only
 *   referenced by the published side is on the public page right now; a file
 *   only referenced by the draft side is one Publish away from being on it.
 * - **Soft-deleted rows included.** `deleted` is a draft flag, not a delete —
 *   the public page still renders that block, and Discard brings it back.
 *   There is no WHERE clause here on purpose.
 * - **Scalar hydration, streamed.** An established install has six figures of
 *   blocks; hydrating entities to read two JSON columns would load the whole
 *   table into the identity map.
 */
final class ContentAreaAssetReferenceProvider implements AssetReferenceProviderInterface
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
        yield from $this->pathsIn(
            'SELECT b.publishedData AS published, b.draftData AS draft FROM ' . Block::class . ' b',
        );

        yield from $this->pathsIn(
            'SELECT s.publishedSettings AS published, s.draftSettings AS draft FROM ' . Section::class . ' s',
        );
    }

    /**
     * @return iterable<string>
     */
    private function pathsIn(string $dql): iterable
    {
        $rows = $this->em->createQuery($dql)->toIterable([], AbstractQuery::HYDRATE_SCALAR);

        foreach ($rows as $row) {
            foreach (['published', 'draft'] as $slot) {
                // Scalar hydration hands back the raw JSON string, not an
                // array — see JsonPayload, and do not "simplify" this away.
                $payload = JsonPayload::decode(\is_array($row) ? ($row[$slot] ?? null) : null);
                if ($payload === []) {
                    continue;
                }

                yield from $this->collector->collect($payload);
            }
        }
    }
}
