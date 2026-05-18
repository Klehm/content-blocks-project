<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Provides default values for a block's `data` payload (the per-block
 * JSON stored in `cb_block.data` and `cb_block.draft_data`).
 *
 * Block-side mirror of
 * {@see \ContentBlocks\Section\SectionSettingsDefaultsProviderInterface}:
 * the default-merging happens on form *load* so widgets without an
 * "empty" state (notably `<input type="color">`) don't surprise the
 * user with browser defaults like #000000.
 *
 * Tag with `content_blocks.block_data_defaults` (autoconfigured by the
 * bundle when implementing this interface). Defaults are merged
 * recursively so providers can declare nested keys, e.g.
 * `['styling' => ['backgroundColor' => '#ffffff']]`.
 */
interface BlockDataDefaultsProviderInterface
{
    /** @return array<string, mixed> */
    public function getDefaults(): array;
}
