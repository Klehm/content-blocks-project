<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Renders a {@see ContentArea} (and its sub-trees) to front-end HTML.
 *
 * This is the override seam for rendering: the bundle aliases it to the shipped
 * {@see BlockRenderer}. A host that needs to customize rendering (wrap output,
 * swap the mode heuristic, add caching…) re-aliases the interface to its own
 * implementation — typically decorating the default via
 * `#[AsDecorator(BlockRendererInterface::class)]`.
 */
interface BlockRendererInterface
{
    /**
     * Query parameter that requests PREVIEW mode (honored only when the
     * AccessChecker grants edit access to the area).
     */
    public const QUERY_PARAM = 'cb_preview';

    /**
     * Render a full content area. Mode is auto-detected from the current
     * request unless $forceMode is given.
     */
    public function render(ContentArea $area, ?RenderMode $forceMode = null): string;

    /**
     * Resolve the render mode (PUBLIC vs PREVIEW) for an area from the current
     * request and the AccessChecker.
     */
    public function resolveMode(ContentArea $area): RenderMode;

    /**
     * Render a single block (used by the builder's live preview endpoints).
     */
    public function renderBlock(Block $block, RenderMode $mode = RenderMode::PREVIEW): string;

    /**
     * Render a single section with its columns and blocks.
     */
    public function renderSection(Section $section, RenderMode $mode = RenderMode::PREVIEW): string;
}
