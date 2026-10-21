<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Library;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Media\Library\MediaUsage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class MediaUsageTest extends TestCase
{
    private string $sql = '';
    /** @var array<string, mixed> */
    private array $params = [];

    public function testDraftAndPublishedUsesArePerArea(): void
    {
        $usage = $this->usage([
            $this->row(1, draft: ['src' => '/uploads/a.png'], published: ['src' => '/uploads/a.png']),
            $this->row(2, draft: ['src' => '/uploads/a.png'], published: null),
            $this->row(2, draft: null, published: ['src' => '/uploads/a.png']),
        ]);

        $this->assertSame([
            1 => ['draft' => true, 'published' => true],
            2 => ['draft' => true, 'published' => true],
        ], $usage->areasUsing('/uploads/a.png'));
    }

    // A deleted block still renders publicly until Publish.
    public function testADeletedBlockCountsOnlyWhereItIsStillPublished(): void
    {
        $usage = $this->usage([
            $this->row(5, draft: ['src' => '/uploads/a.png'], published: ['src' => '/uploads/a.png'], deleted: true),
            $this->row(6, draft: ['src' => '/uploads/a.png'], published: null, deleted: true),
        ]);

        $this->assertSame([5 => ['draft' => false, 'published' => true]], $usage->areasUsing('/uploads/a.png'));
    }

    public function testAFileWhoseNameContainsAnotherIsNotIt(): void
    {
        $usage = $this->usage([$this->row(1, draft: ['src' => '/uploads/aa.png'], published: null)]);

        $this->assertSame([], $usage->areasUsing('/uploads/a.png'));
    }

    public function testAReferenceInsideRichTextIsFound(): void
    {
        $usage = $this->usage([
            $this->row(3, draft: ['html' => '<p>Hi</p><img src="/uploads/a.png">'], published: null),
        ]);

        $this->assertSame([3 => ['draft' => true, 'published' => false]], $usage->areasUsing('/uploads/a.png'));
    }

    public function testTheFilterIsTheFileNameWithItsWildcardsEscaped(): void
    {
        $this->usage([])->areasUsing('/uploads/blocks/50%_off.png');

        $this->assertSame(['name' => '%50\%\_off.png%'], $this->params);
        $this->assertStringContainsString('b.draft_data LIKE :name', $this->sql);
    }

    public function testPostgresqlCompareTheJsonAsText(): void
    {
        $this->usage([], new PostgreSQLPlatform())->areasUsing('/uploads/a.png');

        $this->assertStringContainsString('CAST(b.draft_data AS TEXT) LIKE :name', $this->sql);
        $this->assertStringContainsString('CAST(b.published_data AS TEXT) LIKE :name', $this->sql);
    }

    /**
     * @param array<string, mixed>|null $draft
     * @param array<string, mixed>|null $published
     *
     * @return array<string, mixed>
     */
    private function row(int $area, ?array $draft, ?array $published, bool $deleted = false): array
    {
        return [
            'content_area_id' => (string) $area,
            // Raw JSON strings, like DBAL returns them; MySQL booleans as "0"/"1".
            'draft_data' => $draft === null ? null : json_encode($draft),
            'published_data' => $published === null ? null : json_encode($published),
            'deleted' => $deleted ? '1' : '0',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function usage(array $rows, ?AbstractPlatform $platform = null): MediaUsage
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform ?? new MySQLPlatform());
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) use ($rows) {
            $this->sql = $sql;
            $this->params = $params;

            return $rows;
        });

        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(static fn (string $v) => str_starts_with($v, '/uploads/'));

        return new MediaUsage($connection, new AssetReferenceCollector($resolver));
    }
}
