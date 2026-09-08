<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Authorizes reading the unreferenced-assets report.
 *
 * A cross-area concern with no {@see \ContentBlocks\Entity\ContentArea} to key
 * off — the report covers the whole upload directory — so it gets its own
 * capability rather than overloading
 * {@see \ContentBlocks\Security\AccessCheckerInterface}. Same shape and same
 * reasoning as {@see \ContentBlocks\SectionTemplate\SectionTemplateManagerInterface}.
 *
 * The report lists file paths and sizes across every area an install holds, so
 * the default {@see DenyAllAssetReportViewer} blocks it: a host opts in by
 * aliasing this interface, and the page 404s until it does.
 */
interface AssetReportViewerInterface
{
    public function canViewAssetReport(): bool;
}
