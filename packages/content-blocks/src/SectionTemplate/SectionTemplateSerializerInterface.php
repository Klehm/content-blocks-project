<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Entity\Section;

/**
 * Snapshots one Section into a self-contained array for the reusable section
 * library. Override seam; asset references stay plain storage paths.
 *
 * @see docs/internals/section-templates.md#what-a-snapshot-holds
 */
interface SectionTemplateSerializerInterface
{
    /**
     * Identifier written to the payload's `format` key — see
     * {@see ContentAreaExporterInterface::FORMAT} for why it lives here.
     */
    public const FORMAT = 'content-blocks/section-v1';

    /**
     * Draft wins, soft-deleted entities are skipped, order is previewPosition.
     * The distinct block-type ids come back alongside the payload.
     *
     * @see docs/internals/section-templates.md#what-a-snapshot-holds
     */
    public function serialize(Section $section): SectionTemplateSnapshot;
}
