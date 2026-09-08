<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\StoredAsset;

/**
 * Mark and sweep for uploaded files.
 *
 * ---- Why nothing deletes on delete ----
 *
 * Deleting a file when the block that showed it is deleted is wrong here, and
 * not by a small margin:
 *
 * - `deleted` is a **draft flag**. The published page still renders that block
 *   until someone hits Publish, and Discard brings it back. Unlinking the file
 *   at that moment breaks a live page — the exact class of bug
 *   `PublishedRenderImmutabilityTest` exists to prevent.
 * - Even at Publish time the file may still be reachable: the block's own
 *   published/draft twins, another block after a copy/paste (the clipboard
 *   duplicates the *path*, so two blocks share one file), another area after
 *   an Insert content deep clone, a saved section template, a translated
 *   rich-text value, or the host's own entities sharing the upload directory.
 *
 * Every one of those makes a reference count computed at delete time either
 * wrong or as expensive as the full scan below. So: full scan, off the hot
 * path, when an operator asks for it.
 *
 * ---- Why the order of operations is the safety property ----
 *
 * The inventory is snapshotted **before** references are collected. A file
 * uploaded while the marking runs is therefore not in the snapshot and cannot
 * be swept, no matter what the reference scan concludes about it. The
 * retention window then covers the other race, the one that actually bites:
 * `/_content-blocks/upload` writes the file the moment the editor picks it,
 * minutes before the block form that will reference it is submitted.
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
     * False when the configured storage cannot enumerate itself, in which case
     * {@see self::collect()} throws. Callers report this rather than pretending
     * a sweep of nothing succeeded.
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
            throw new \LogicException(sprintf(
                'Storage "%s" cannot enumerate its files, so unreferenced assets cannot be identified. '
                . 'Implement %s on it (see %s) or run the sweep with your storage provider\'s own tooling.',
                $this->storage::class,
                AssetInventoryInterface::class,
                \ContentBlocks\Storage\LocalFileStorage::class,
            ));
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

        // 2. Mark. Exact string comparison against the spelling stored in the
        //    payload — a normalization step here would be a second, divergent
        //    definition of "the same file".
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
