<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\ContentArea;

/**
 * Serializes a ContentArea into a JSON-encodable manifest; the files it lists
 * travel beside it. The frozen contract is the payload shape, not this call.
 *
 * @see docs/internals/transfer.md#the-payload-shape-is-the-contract
 */
interface ContentAreaExporterInterface
{
    /**
     * Written to, and expected back from, the payload's `format` key.
     *
     * @see docs/internals/transfer.md#the-payload-shape-is-the-contract
     */
    public const FORMAT = 'content-blocks/v1';

    /**
     * Draft wins, soft-deleted entities are skipped, order is previewPosition.
     * `assets` lists the files, not their bytes: the zip carries them beside.
     *
     * @see docs/internals/transfer.md#what-is-exported
     *
     * @return array{
     *     format: string,
     *     contentVersion: int,
     *     exportedAt: string,
     *     contentArea: array{sections: list<array<string, mixed>>},
     *     assets: array<string, array{
     *         mimeType: string,
     *         extension: string,
     *         size: int,
     *         path: string,
     *     }>,
     *     extensions?: array<string, array<string, mixed>>,
     * }
     */
    public function export(ContentArea $area): array;
}
