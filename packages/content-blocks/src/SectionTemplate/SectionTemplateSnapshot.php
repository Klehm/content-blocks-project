<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Outcome of snapshotting a Section into the library: the self-contained payload
 * to store, plus the distinct block-type identifiers it references.
 *
 * The identifiers are kept alongside (denormalized into `cb_section_template.block_types`)
 * so the picker can flag an unusable template from a cheap column read, without
 * deserializing every payload to look for its block types.
 */
final class SectionTemplateSnapshot
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $blockTypes
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $blockTypes,
    ) {
    }
}
