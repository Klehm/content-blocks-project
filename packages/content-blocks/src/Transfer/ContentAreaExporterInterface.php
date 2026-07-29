<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\ContentArea;

/**
 * Serializes a ContentArea into a self-contained, JSON-encodable array.
 *
 * Override seam: the bundle aliases this to the shipped {@see ContentAreaExporter}.
 *
 * The frozen contract here is not the PHP signature — it is the **payload
 * shape**, which travels on disk and between installations. {@see FORMAT}
 * versions it: a reader must refuse a payload whose `format` it does not know
 * rather than guess. Changing the shape means bumping the format string.
 */
interface ContentAreaExporterInterface
{
    /**
     * Identifier written to (and expected back from) the payload's `format`
     * key. Lives on the interface, not the implementation, so a host that
     * swaps the exporter does not leave {@see ContentAreaImporterInterface}
     * validating against the shipped class.
     */
    public const FORMAT = 'content-blocks/v1';

    /**
     * Draft state takes precedence over published state, soft-deleted entities
     * are skipped, and everything is ordered by previewPosition — the same
     * convention as the clone, replace and rendering pipelines.
     *
     * Asset references found inside block data / section settings are read from
     * storage, embedded as base64 under their sha256 hash (identical binaries
     * deduplicated), and replaced in place by an `asset://{hash}` token. A
     * reference that cannot be read keeps its original path, so the import side
     * sees a broken reference rather than a silently dropped field.
     *
     * @return array{
     *     format: string,
     *     exportedAt: string,
     *     contentArea: array{sections: list<array<string, mixed>>},
     *     assets: array<string, array{mimeType: string, extension: string, data: string}>
     * }
     */
    public function export(ContentArea $area): array;
}
