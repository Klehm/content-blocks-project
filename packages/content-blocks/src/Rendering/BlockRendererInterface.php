<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * Renders a {@see ContentArea} to front-end HTML. Override seam for rendering;
 * to change what a block renders, prefer {@see BlockDataResolverInterface}.
 *
 * @see docs/internals/rendering.md#replacing-the-renderer-itself
 */
interface BlockRendererInterface
{
    /**
     * Query parameter that requests PREVIEW mode (honored only when the
     * AccessChecker grants edit access to the area).
     */
    public const QUERY_PARAM = 'cb_preview';

    /**
     * Set to `0` alongside {@see self::QUERY_PARAM} to render draft content
     * without the editing chrome. Any other value keeps it.
     *
     * @see docs/internals/rendering.md#why-chrome-is-a-separate-flag-from-mode
     */
    public const CHROME_QUERY_PARAM = 'cb_chrome';

    /**
     * Render a full content area. With no context — or a context whose mode is
     * null — the mode is auto-detected from the current request.
     */
    public function render(ContentArea $area, ?RenderContext $context = null): string;

    /**
     * Resolve the render mode (PUBLIC vs PREVIEW) for an area from the current
     * request and the AccessChecker.
     */
    public function resolveMode(ContentArea $area): RenderMode;

    /**
     * Render a single block (used by the builder's live preview endpoints).
     * Defaults to PREVIEW when the context carries no mode.
     */
    public function renderBlock(Block $block, ?RenderContext $context = null): string;

    /**
     * Render a single section with its columns and blocks.
     * Defaults to PREVIEW when the context carries no mode.
     */
    public function renderSection(Section $section, ?RenderContext $context = null): string;
}
