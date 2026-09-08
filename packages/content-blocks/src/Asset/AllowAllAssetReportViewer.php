<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Grants the unreferenced-assets report to anyone who can reach the route.
 * For development and sandboxes — in production the host's own implementation
 * should ask its authorization layer, exactly as it does for
 * {@see \ContentBlocks\Security\AccessCheckerInterface}.
 */
final class AllowAllAssetReportViewer implements AssetReportViewerInterface
{
    public function canViewAssetReport(): bool
    {
        return true;
    }
}
