<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Storage\StoredAsset;

/**
 * What one {@see AssetGarbageCollector} run saw and did. Immutable, and the
 * same shape whether the run was a dry run or not — the command renders it,
 * `--format=json` serializes it, and a host can build its own report page on
 * it without re-deriving anything.
 */
final class AssetGarbageReport
{
    /**
     * @param list<StoredAsset> $swept    unreferenced, past the retention window — deleted unless $dryRun
     * @param list<StoredAsset> $withheld unreferenced but younger than the retention window
     * @param list<string>      $failed   paths the storage refused to delete
     */
    public function __construct(
        public readonly int $scanned,
        public readonly int $referencedPaths,
        public readonly array $swept,
        public readonly array $withheld,
        public readonly array $failed,
        public readonly bool $dryRun,
        public readonly int $retentionDays,
    ) {
    }

    /** Bytes the sweep freed — or would free, on a dry run. */
    public function reclaimedBytes(): int
    {
        return array_sum(array_map(static fn (StoredAsset $a) => $a->size, $this->swept));
    }

    /** Bytes held back by the retention window; candidates for the next run. */
    public function withheldBytes(): int
    {
        return array_sum(array_map(static fn (StoredAsset $a) => $a->size, $this->withheld));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'retentionDays' => $this->retentionDays,
            'scanned' => $this->scanned,
            'referencedPaths' => $this->referencedPaths,
            'reclaimedBytes' => $this->reclaimedBytes(),
            'withheldBytes' => $this->withheldBytes(),
            'swept' => array_map($this->describe(...), $this->swept),
            'withheld' => array_map($this->describe(...), $this->withheld),
            'failed' => $this->failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(StoredAsset $asset): array
    {
        return [
            'path' => $asset->publicPath,
            'size' => $asset->size,
            'lastModifiedAt' => $asset->lastModifiedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
