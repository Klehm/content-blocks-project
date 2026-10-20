<?php

declare(strict_types=1);

namespace ContentBlocks\Headless;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\RenderContext;

/**
 * Renders a {@see ContentArea} as data instead of HTML, for a headless front,
 * an app or a search index. Same selection rules as the HTML render.
 *
 * @see docs/guide/headless.md
 */
interface ContentAreaNormalizerInterface
{
    /** Shape of the tree; bumped only by a major release. */
    public const FORMAT = 1;

    /**
     * With no mode in the context it is decided from the request, as for the
     * HTML render: PREVIEW only for `?cb_preview=1` and an editor.
     *
     * @return array{
     *     format: int,
     *     id: ?int,
     *     mode: string,
     *     locale: ?string,
     *     contentVersion: ?int,
     *     sections: list<array<string, mixed>>,
     * }
     */
    public function normalize(ContentArea $area, ?RenderContext $context = null): array;

    /**
     * Null when the mode does not show the section: unpublished in PUBLIC,
     * soft-deleted in PREVIEW.
     *
     * @return array<string, mixed>|null
     */
    public function normalizeSection(Section $section, ?RenderContext $context = null): ?array;

    /**
     * Null when the mode does not show the block, as for a section.
     *
     * @return array{
     *     id: ?int,
     *     type: string,
     *     data: array<string, mixed>,
     *     order: array{tablet: ?int, mobile: ?int},
     * }|null
     */
    public function normalizeBlock(Block $block, ?RenderContext $context = null): ?array;

    /**
     * {@see normalize()} as JSON, safe inside `<script>` or an attribute;
     * `settings` and `data` stay objects when empty.
     */
    public function toJson(ContentArea $area, ?RenderContext $context = null): string;
}
