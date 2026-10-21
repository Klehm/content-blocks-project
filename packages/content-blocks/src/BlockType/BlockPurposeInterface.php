<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Optional companion to {@see BlockTypeInterface}: what a block is for, so a
 * model composing a page picks it for the right job.
 *
 * @see docs/guide/content-schema.md#block-purposes
 */
interface BlockPurposeInterface
{
    /**
     * One or two plain sentences, in English, read by a language model: the
     * job the block does and when to prefer it over a neighbour.
     */
    public function getPurpose(): string;
}
