<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Denies the unreferenced-assets report, so a host opts in by aliasing
 * {@see AssetReportViewerInterface} to its own.
 */
final class DenyAllAssetReportViewer implements AssetReportViewerInterface
{
    public function canViewAssetReport(): bool
    {
        return false;
    }
}
