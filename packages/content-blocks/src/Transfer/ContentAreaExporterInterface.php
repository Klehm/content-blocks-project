<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\ContentArea;

/**
 * Serializes a ContentArea into a self-contained, JSON-encodable array. The
 * frozen contract is the payload shape, not this signature.
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
     * Assets are embedded; `contentVersion` is informative only.
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
     *         data: string,
     *     }>,
     *     extensions?: array<string, array<string, mixed>>,
     * }
     */
    public function export(ContentArea $area): array;
}
