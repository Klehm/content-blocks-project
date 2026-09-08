<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Grants the report to anyone who can reach the route — development and
 * sandboxes only; production asks its own authorization layer.
 */
final class AllowAllAssetReportViewer implements AssetReportViewerInterface
{
    public function canViewAssetReport(): bool
    {
        return true;
    }
}
