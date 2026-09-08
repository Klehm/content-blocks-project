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
 * Asset references held by translated values.
 *
 * This package is exactly why the sweep needs a seam rather than a hard-coded
 * list of tables: a translated rich-text value is a *separate row in a
 * separate table*, and it carries its own `<img src="/uploads/…">`. An editor
 * who uploads an illustration while writing the German version of a page
 * creates a file that no block's data mentions anywhere. Without this
 * provider the sweep would delete it and empty the German page.
 *
 * Both slots are read, for the same reason the core provider reads both:
 * published values are on the public site now, draft values are one Publish
 * away.
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
