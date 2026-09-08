<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Asset;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\I18n\Asset\TranslationAssetReferenceProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

final class TranslationAssetReferenceProviderTest extends TestCase
{
    private function makeCollector(): AssetReferenceCollector
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            static fn (string $value) => str_starts_with($value, '/uploads/'),
        );

        return new AssetReferenceCollector($resolver);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function makeProvider(array $rows): TranslationAssetReferenceProvider
    {
        $query = $this->createMock(Query::class);
        $query->method('toIterable')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);

        return new TranslationAssetReferenceProvider($em, $this->makeCollector());
    }

    /**
     * The case this provider exists for: an image uploaded while writing the
     * German version of a page is referenced by a translation row and by
     * nothing in `cb_block`. Without this, the sweep would delete it.
     */
    public function testAnImageOnlyEverUsedInATranslatedValueIsReported(): void
    {
        $provider = $this->makeProvider([[
            'published' => ['html' => '<p>Hallo</p><img src="/uploads/de-only.png">'],
            'draft' => null,
        ]]);

        $this->assertSame(
            ['/uploads/de-only.png'],
            iterator_to_array($provider->referencedAssetPaths(), false),
        );
    }

    public function testBothSlotsAreRead(): void
    {
        $provider = $this->makeProvider([
            ['published' => ['title' => '/uploads/published.png'], 'draft' => null],
            ['published' => null, 'draft' => ['title' => '/uploads/draft.png']],
        ]);

        $this->assertSame(
            ['/uploads/published.png', '/uploads/draft.png'],
            iterator_to_array($provider->referencedAssetPaths(), false),
        );
    }

    /**
     * Values are keyed by field path, including collection-entry paths — the
     * keys are irrelevant to the scan, only the leaves matter.
     */
    public function testFlatFieldPathKeysAreScannedLikeAnyOtherPayload(): void
    {
        $provider = $this->makeProvider([[
            'published' => [
                'title' => 'Willkommen',
                'items[9f2c1a].html' => '<img src="/uploads/entry.png">',
            ],
            'draft' => null,
        ]]);

        $this->assertSame(
            ['/uploads/entry.png'],
            iterator_to_array($provider->referencedAssetPaths(), false),
        );
    }

    /**
     * Same trap as the core providers: HYDRATE_SCALAR hands back the raw JSON
     * string, and treating a non-array as "nothing referenced" would let the
     * sweep delete every image that only a translation points at.
     */
    public function testRowsArriveAsRawJsonStringsAndAreStillScanned(): void
    {
        $provider = $this->makeProvider([[
            'published' => '{"html": "<img src=\"/uploads/de-only.png\">"}',
            'draft' => null,
        ]]);

        $this->assertSame(
            ['/uploads/de-only.png'],
            iterator_to_array($provider->referencedAssetPaths(), false),
        );
    }

    public function testEmptyTranslationRowsYieldNothing(): void
    {
        $provider = $this->makeProvider([['published' => null, 'draft' => []]]);

        $this->assertSame([], iterator_to_array($provider->referencedAssetPaths(), false));
    }
}
