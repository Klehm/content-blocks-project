<?php

declare(strict_types=1);

namespace ContentBlocks\Snapshot;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;

/**
 * Carries what a bundle stores beside a block through a section template or
 * the clipboard. Autoconfigured; lands under `extensions.<key>`.
 *
 * @see docs/internals/section-templates.md#rows-kept-beside-a-block
 */
interface SnapshotExtensionInterface
{
    /** Payload key; namespace it like a package — `acme/reviews`. */
    public function key(): string;

    /**
     * Refs are positions (`c0`, `c0.b1`) and a fragment addresses nothing
     * else. The payload keeps plain storage paths, as the snapshot does.
     *
     * @param array<string, Block>  $blocks  ref => block being copied
     * @param array<string, Column> $columns ref => column being copied
     *
     * @return array<string, mixed> empty when there is nothing to carry
     */
    public function capture(array $blocks, array $columns): array;

    /**
     * Called once the copies are flushed. A block the paste skipped is
     * absent, and the fragment is untrusted: a clipboard lives in a browser.
     *
     * @param array<string, Block>  $blocks   ref => copy just created
     * @param array<string, Column> $columns  ref => copy just created
     * @param array<string, mixed>  $fragment what {@see capture()} wrote
     */
    public function restore(array $blocks, array $columns, array $fragment): void;
}
