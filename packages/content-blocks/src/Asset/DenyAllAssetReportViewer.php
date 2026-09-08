<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Default implementation: denies the unreferenced-assets report. Forces the
 * host to opt in by aliasing {@see AssetReportViewerInterface} to its own
 * implementation.
 */
final class DenyAllAssetReportViewer implements AssetReportViewerInterface
{
    public function canViewAssetReport(): bool
    {
        return false;
    }
}
