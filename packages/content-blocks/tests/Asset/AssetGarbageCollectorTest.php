<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Asset;

use ContentBlocks\Asset\AssetGarbageCollector;
use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\NullFileStorage;
use ContentBlocks\Storage\StoredAsset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AssetGarbageCollectorTest extends TestCase
{
    /** @var list<string> */
    private array $removed = [];

    /**
     * In-memory storage that can also enumerate itself — the shape
     * LocalFileStorage has, without touching a disk.
     *
     * @param array<string, int> $files path => age in days
     */
    private function makeStorage(array $files, ?string $failsOn = null): FileStorageInterface&AssetInventoryInterface
    {
        $this->removed = [];
        $now = new \DateTimeImmutable();

        return new class ($files, $now, $this->removed, $failsOn) implements FileStorageInterface, AssetInventoryInterface {
            /** @param array<string, int> $files */
            public function __construct(
                private array $files,
                private \DateTimeImmutable $now,
                private array &$removed,
                private ?string $failsOn,
            ) {
            }

            public function listAssets(): iterable
            {
                foreach ($this->files as $path => $ageInDays) {
                    yield new StoredAsset($path, 100, $this->now->modify(sprintf('-%d days', $ageInDays)));
                }
            }

            public function remove(string $path): void
            {
                if ($path === $this->failsOn) {
                    throw new \RuntimeException('Permission denied');
                }
                $this->removed[] = $path;
            }

            public function upload(UploadedFile $file, string $directory = ''): string
            {
                throw new \LogicException('not used');
            }

            public function isStoredPath(string $value): bool
            {
                return str_starts_with($value, '/uploads/');
            }

            public function read(string $publicPath): ?string
            {
                return null;
            }

            public function uploadFromString(string $contents, string $extension, string $directory = ''): string
            {
                throw new \LogicException('not used');
            }
        };
    }

    /**
     * @param list<string> $paths
     */
    private function makeProvider(array $paths): AssetReferenceProviderInterface
    {
        $provider = $this->createMock(AssetReferenceProviderInterface::class);
        $provider->method('referencedAssetPaths')->willReturn($paths);

        return $provider;
    }

    public function testUnreferencedFilesPastTheRetentionWindowAreSwept(): void
    {
        $storage = $this->makeStorage(['/uploads/kept.png' => 90, '/uploads/orphan.png' => 90]);
        $collector = new AssetGarbageCollector($storage, [$this->makeProvider(['/uploads/kept.png'])]);

        $report = $collector->collect(dryRun: false, retentionDays: 30);

        $this->assertSame(['/uploads/orphan.png'], array_map(static fn ($a) => $a->publicPath, $report->swept));
        $this->assertSame(['/uploads/orphan.png'], $this->removed);
        $this->assertSame(2, $report->scanned);
        $this->assertSame(1, $report->referencedPaths);
    }

    /**
     * The race that actually happens: `/_content-blocks/upload` writes the file
     * the moment the editor picks it, and the block form referencing it is
     * submitted minutes later. Nothing may sweep in between.
     */
    public function testAFileYoungerThanTheRetentionWindowIsWithheldEvenWhenNothingReferencesIt(): void
    {
        $storage = $this->makeStorage(['/uploads/just-uploaded.png' => 0]);
        $collector = new AssetGarbageCollector($storage, []);

        $report = $collector->collect(dryRun: false, retentionDays: 30);

        $this->assertSame([], $report->swept);
        $this->assertSame([], $this->removed);
        $this->assertSame(
            ['/uploads/just-uploaded.png'],
            array_map(static fn ($a) => $a->publicPath, $report->withheld),
        );
    }

    public function testADryRunReportsTheSameFilesButDeletesNothing(): void
    {
        $storage = $this->makeStorage(['/uploads/orphan.png' => 90]);
        $collector = new AssetGarbageCollector($storage, []);

        $report = $collector->collect(dryRun: true, retentionDays: 30);

        $this->assertSame(['/uploads/orphan.png'], array_map(static fn ($a) => $a->publicPath, $report->swept));
        $this->assertTrue($report->dryRun);
        $this->assertSame([], $this->removed, 'a dry run must not touch the storage');
    }

    public function testDryRunIsTheDefault(): void
    {
        $storage = $this->makeStorage(['/uploads/orphan.png' => 90]);

        $report = (new AssetGarbageCollector($storage, []))->collect();

        $this->assertTrue($report->dryRun);
        $this->assertSame([], $this->removed);
        $this->assertSame(AssetGarbageCollector::DEFAULT_RETENTION_DAYS, $report->retentionDays);
    }

    /**
     * Every provider is consulted and their sets unioned — this is what keeps
     * a host's own references, and the i18n package's, on equal footing with
     * the core's.
     */
    public function testReferencesFromEveryProviderAreUnioned(): void
    {
        $storage = $this->makeStorage([
            '/uploads/by-block.png' => 90,
            '/uploads/by-template.png' => 90,
            '/uploads/by-host.png' => 90,
            '/uploads/orphan.png' => 90,
        ]);

        $report = (new AssetGarbageCollector($storage, [
            $this->makeProvider(['/uploads/by-block.png']),
            $this->makeProvider(['/uploads/by-template.png']),
            $this->makeProvider(['/uploads/by-host.png']),
        ]))->collect(dryRun: false, retentionDays: 30);

        $this->assertSame(['/uploads/orphan.png'], $this->removed);
        $this->assertSame(3, $report->referencedPaths);
    }

    public function testAReferenceToAFileTheStorageDoesNotHoldIsHarmless(): void
    {
        $storage = $this->makeStorage(['/uploads/orphan.png' => 90]);

        $report = (new AssetGarbageCollector($storage, [
            $this->makeProvider(['/uploads/long-gone.png', '/uploads/orphan.png.']),
        ]))->collect(dryRun: false, retentionDays: 30);

        $this->assertSame(['/uploads/orphan.png'], $this->removed);
        $this->assertSame(2, $report->referencedPaths);
    }

    public function testAStorageFailureIsReportedRatherThanAbortingTheRun(): void
    {
        $storage = $this->makeStorage(
            ['/uploads/locked.png' => 90, '/uploads/orphan.png' => 90],
            failsOn: '/uploads/locked.png',
        );

        $report = (new AssetGarbageCollector($storage, []))->collect(dryRun: false, retentionDays: 30);

        $this->assertSame(['/uploads/locked.png'], $report->failed);
        $this->assertSame(['/uploads/orphan.png'], array_map(static fn ($a) => $a->publicPath, $report->swept));
        $this->assertSame(['/uploads/orphan.png'], $this->removed);
    }

    public function testRetentionZeroSweepsEverythingUnreferenced(): void
    {
        $storage = $this->makeStorage(['/uploads/just-uploaded.png' => 0]);

        $report = (new AssetGarbageCollector($storage, []))->collect(dryRun: false, retentionDays: 0);

        $this->assertSame(['/uploads/just-uploaded.png'], $this->removed);
        $this->assertSame([], $report->withheld);
    }

    public function testANegativeRetentionIsRefused(): void
    {
        $collector = new AssetGarbageCollector($this->makeStorage([]), []);

        $this->expectException(\InvalidArgumentException::class);
        $collector->collect(dryRun: false, retentionDays: -1);
    }

    public function testAStorageThatCannotEnumerateIsUnsupportedRatherThanASweepOfNothing(): void
    {
        $collector = new AssetGarbageCollector(new NullFileStorage(), []);

        $this->assertFalse($collector->isSupported());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot enumerate/');
        $collector->collect();
    }

    public function testTheReportSumsBytesOnEachSide(): void
    {
        $storage = $this->makeStorage(['/uploads/orphan.png' => 90, '/uploads/fresh.png' => 1]);

        $report = (new AssetGarbageCollector($storage, []))->collect(dryRun: true, retentionDays: 30);

        $this->assertSame(100, $report->reclaimedBytes());
        $this->assertSame(100, $report->withheldBytes());

        $serialized = $report->toArray();
        $this->assertSame(2, $serialized['scanned']);
        $this->assertSame('/uploads/orphan.png', $serialized['swept'][0]['path']);
        $this->assertSame('/uploads/fresh.png', $serialized['withheld'][0]['path']);
    }
}
