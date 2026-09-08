<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Asset;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Asset\ContentAreaAssetReferenceProvider;
use ContentBlocks\Asset\SectionTemplateAssetReferenceProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

/**
 * The two shipped mark sources. The DQL itself is exercised against a real
 * database by the sandbox (`content-blocks:assets:gc`); what matters here is
 * which slots are read and what is done with each row.
 */
final class ReferenceProviderTest extends TestCase
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
     * EM double returning one canned scalar result set per createQuery() call,
     * in order.
     *
     * @param list<list<array<string, mixed>>> $resultSets
     */
    private function makeEm(array $resultSets): EntityManagerInterface
    {
        $queries = [];
        foreach ($resultSets as $rows) {
            $query = $this->createMock(Query::class);
            $query->method('toIterable')->willReturn($rows);
            $queries[] = $query;
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturnOnConsecutiveCalls(...$queries);

        return $em;
    }

    /**
     * Both twins, because a file referenced only by the published side is on
     * the public page right now, and one referenced only by the draft side is
     * one Publish away from being there.
     */
    public function testBlockProviderReadsPublishedAndDraftData(): void
    {
        $em = $this->makeEm([
            [
                ['published' => ['src' => '/uploads/published.png'], 'draft' => null],
                ['published' => null, 'draft' => ['src' => '/uploads/draft.png']],
            ],
            [],
        ]);

        $paths = iterator_to_array(
            (new ContentAreaAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame(['/uploads/published.png', '/uploads/draft.png'], $paths);
    }

    public function testBlockProviderFindsPathsBuriedInRichTextAndCollections(): void
    {
        $em = $this->makeEm([
            [[
                'published' => [
                    'html' => '<p>Hi</p><img src="/uploads/inline.png">',
                    'items' => [['_id' => 'a1', 'src' => '/uploads/card.png']],
                ],
                'draft' => null,
            ]],
            [],
        ]);

        $paths = iterator_to_array(
            (new ContentAreaAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame(['/uploads/inline.png', '/uploads/card.png'], $paths);
    }

    /**
     * Regression, found by running the sweep against a real database: an
     * aliased `json` column read with HYDRATE_SCALAR comes back as its raw
     * JSON **string**, so an `is_array()` check skipped every row and the
     * collector reported zero references — which in a sweep means "delete
     * everything". This is the shape MySQL actually returns.
     */
    public function testRowsArriveAsRawJsonStringsAndAreStillScanned(): void
    {
        $em = $this->makeEm([
            [[
                'published' => '{"alt": null, "src": "/uploads/real-shape.png"}',
                'draft' => null,
            ]],
            [],
        ]);

        $paths = iterator_to_array(
            (new ContentAreaAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame(['/uploads/real-shape.png'], $paths);
    }

    public function testSectionTemplatePayloadsArriveAsRawJsonStringsToo(): void
    {
        $em = $this->makeEm([
            [['payload' => '{"blocks": [{"data": {"src": "/uploads/in-library.png"}}]}']],
        ]);

        $paths = iterator_to_array(
            (new SectionTemplateAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame(['/uploads/in-library.png'], $paths);
    }

    public function testSectionSettingsAreScannedToo(): void
    {
        $em = $this->makeEm([
            [],
            [['published' => ['styling' => ['backgroundImage' => '/uploads/bg.png']], 'draft' => null]],
        ]);

        $paths = iterator_to_array(
            (new ContentAreaAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame(['/uploads/bg.png'], $paths);
    }

    public function testEmptyAndNullSlotsAreSkipped(): void
    {
        $em = $this->makeEm([
            [['published' => null, 'draft' => []], ['published' => [], 'draft' => null]],
            [],
        ]);

        $paths = iterator_to_array(
            (new ContentAreaAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame([], $paths);
    }

    /**
     * The guarantee the whole design rests on: `deleted` is a *draft* flag, so
     * a soft-deleted block still renders on the published page and Discard
     * brings it back. Its image must stay referenced until the deletion is
     * actually published and the row is gone. Enforced by there being no
     * filter at all — assert on the query, since a WHERE added later would
     * pass every other test in this file while quietly making the sweep
     * delete live images.
     */
    public function testNoRowIsFilteredOutSoSoftDeletedContentStillHoldsItsReferences(): void
    {
        $seen = [];
        $query = $this->createMock(Query::class);
        $query->method('toIterable')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturnCallback(function (string $dql) use (&$seen, $query) {
            $seen[] = $dql;

            return $query;
        });

        iterator_to_array(
            (new ContentAreaAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertCount(2, $seen);
        foreach ($seen as $dql) {
            $this->assertStringNotContainsStringIgnoringCase(' WHERE ', $dql, $dql);
            $this->assertStringNotContainsStringIgnoringCase('deleted', $dql, $dql);
        }
    }

    /**
     * A saved template keeps plain storage paths in its payload, so it is a
     * reference holder that outlives every block that ever used the file.
     */
    public function testSectionTemplatePayloadsAreScanned(): void
    {
        $em = $this->makeEm([
            [['payload' => ['blocks' => [['type' => 'image', 'data' => ['src' => '/uploads/in-library.png']]]]]],
        ]);

        $paths = iterator_to_array(
            (new SectionTemplateAssetReferenceProvider($em, $this->makeCollector()))->referencedAssetPaths(),
            false,
        );

        $this->assertSame(['/uploads/in-library.png'], $paths);
    }
}
