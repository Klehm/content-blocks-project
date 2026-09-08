<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\StoredAsset;

/**
 * Mark and sweep for uploaded files. Nothing deletes on delete, and the order
 * of operations here is the safety property.
 *
 * @see docs/internals/assets.md
 */
final class AssetGarbageCollector
{
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * @param iterable<AssetReferenceProviderInterface> $referenceProviders
     */
    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly iterable $referenceProviders = [],
    ) {
    }

    /**
     * False when the storage cannot enumerate itself — {@see self::collect()}
     * then throws rather than report a successful sweep of nothing.
     */
    public function isSupported(): bool
    {
        return $this->storage instanceof AssetInventoryInterface;
    }

    public function collect(
        bool $dryRun = true,
        int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        ?\DateTimeImmutable $now = null,
    ): AssetGarbageReport {
        if (!$this->storage instanceof AssetInventoryInterface) {
            throw new \LogicException(sprintf('Storage "%s" cannot enumerate its files, so unreferenced assets cannot be identified. Implement %s on it (see %s) or run the sweep with your storage provider\'s own tooling.', $this->storage::class, AssetInventoryInterface::class, \ContentBlocks\Storage\LocalFileStorage::class, ));
        }

        if ($retentionDays < 0) {
            throw new \InvalidArgumentException('Retention must be zero or more days.');
        }

        $now ??= new \DateTimeImmutable();
        $cutoff = $now->modify(sprintf('-%d days', $retentionDays));

        // 1. Snapshot first. See the class docblock: this is what makes a
        //    concurrent upload un-sweepable rather than merely unlikely.
        /** @var list<StoredAsset> $inventory */
        $inventory = [];
        foreach ($this->storage->listAssets() as $asset) {
            $inventory[] = $asset;
        }

        // 2. Mark. Exact comparison against the stored spelling: normalizing
        //    here would be a second definition of "the same file".
        /** @var array<string, true> $referenced */
        $referenced = [];
        foreach ($this->referenceProviders as $provider) {
            foreach ($provider->referencedAssetPaths() as $path) {
                if (\is_string($path) && $path !== '') {
                    $referenced[$path] = true;
                }
            }
        }

        // 3. Sweep.
        $swept = [];
        $withheld = [];
        $failed = [];

        foreach ($inventory as $asset) {
            if (isset($referenced[$asset->publicPath])) {
                continue;
            }

            if ($asset->lastModifiedAt > $cutoff) {
                $withheld[] = $asset;

                continue;
            }

            if (!$dryRun) {
                try {
                    $this->storage->remove($asset->publicPath);
                } catch (\Throwable) {
                    $failed[] = $asset->publicPath;

                    continue;
                }
            }

            $swept[] = $asset;
        }

        return new AssetGarbageReport(
            scanned: \count($inventory),
            referencedPaths: \count($referenced),
            swept: $swept,
            withheld: $withheld,
            failed: $failed,
            dryRun: $dryRun,
            retentionDays: $retentionDays,
        );
    }
}
