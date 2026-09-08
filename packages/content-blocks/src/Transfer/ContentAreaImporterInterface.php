<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\ContentArea;

/**
 * Hydrates a payload produced by {@see ContentAreaExporterInterface} into draft
 * sections on a target ContentArea. Override seam.
 */
interface ContentAreaImporterInterface
{
    /**
     * Replace semantics, and does not flush. The envelope is validated
     * strictly, the content taken optimistically.
     *
     * @see docs/internals/transfer.md#import-is-a-replace-and-does-not-flush
     *
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException on an unsupported or invalid envelope
     */
    public function import(ContentArea $target, array $payload): ImportResult;
}
