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
 *
 * Every entry point takes a {@see RenderContext} rather than a bare
 * {@see RenderMode}, so the pipeline can gain inputs (locale today, whatever
 * comes next) without another breaking signature change. Pass null to keep the
 * historical behaviour.
 *
 * To change *what* a block renders rather than how the tree is walked, prefer
 * {@see BlockDataResolverInterface} — a far smaller surface to own than a
 * renderer replacement.
 */
interface BlockRendererInterface
{
    /**
     * Query parameter that requests PREVIEW mode (honored only when the
     * AccessChecker grants edit access to the area).
     */
    public const QUERY_PARAM = 'cb_preview';

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
