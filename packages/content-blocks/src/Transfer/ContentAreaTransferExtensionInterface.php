<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;

/**
 * Carries what a bundle stores *beside* a block — rows of its own — through an
 * export and back. Autoconfigured; payload lands under `extensions.<key>`.
 *
 * @see docs/internals/transfer.md#what-is-stored-beside-a-block
 */
interface ContentAreaTransferExtensionInterface
{
    /**
     * Payload key, and the only thing tying an export to an import. Namespace
     * it like a package — `acme/reviews` — so two bundles cannot collide.
     */
    public function key(): string;

    /**
     * $blocks is keyed by the `ref` the payload gives each block; a fragment
     * addresses a block by that ref and by nothing else.
     *
     * @param array<string, Block> $blocks ref => block being exported
     *
     * @return array<string, mixed> empty when there is nothing to carry
     */
    public function export(ContentArea $area, array $blocks, AssetTokenizer $assets): array;

    /**
     * Called only when the payload carries this key. Blocks the importer
     * skipped are absent from $blocks, so a fragment addressing one is dropped.
     *
     * @param array<string, Block> $blocks   ref => block just built
     * @param array<string, mixed> $fragment what {@see export()} wrote
     */
    public function import(ContentArea $area, array $blocks, array $fragment, AssetRewriter $assets): void;
}
