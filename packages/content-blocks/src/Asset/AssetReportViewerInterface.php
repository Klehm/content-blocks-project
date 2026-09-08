<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Authorizes reading the unreferenced-assets report: a cross-area capability
 * with no ContentArea to key off. Denied until a host aliases it.
 *
 * @see docs/internals/assets.md#three-seams-and-why-each-is-its-own-interface
 */
interface AssetReportViewerInterface
{
    public function canViewAssetReport(): bool;
}
