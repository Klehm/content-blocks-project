<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Entity\Section;

/**
 * Snapshots a single Section into a self-contained array, for JSON storage in a
 * SectionTemplate (the reusable "section library").
 *
 * Override seam: the bundle aliases this to the shipped {@see SectionTemplateSerializer}.
 *
 * Unlike {@see ContentAreaExporterInterface}, asset references stay plain
 * storage paths: the library lives inside one app, so embedding binaries would
 * bloat every template for nothing.
 */
interface SectionTemplateSerializerInterface
{
    /**
     * Identifier written to the payload's `format` key — see
     * {@see ContentAreaExporterInterface::FORMAT} for why it lives here.
     */
    public const FORMAT = 'content-blocks/section-v1';

    /**
     * Draft state takes precedence over published state, soft-deleted entities
     * are skipped, and columns/blocks are ordered by previewPosition.
     *
     * Alongside the payload, the distinct block-type identifiers used are
     * returned so the library can flag an incompatible template cheaply,
     * without deserializing it.
     *
     * @return array{payload: array<string, mixed>, blockTypes: list<string>}
     */
    public function serialize(Section $section): array;
}
