<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * The payload to store, plus the distinct block-type ids it references —
 * denormalized so the picker never has to deserialize a payload to check.
 *
 * @see docs/internals/section-templates.md#what-a-snapshot-holds
 */
final class SectionTemplateSnapshot
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $blockTypes
     *
     * @internal hosts receive these objects, they do not build them; see
     *           FREEZE-AUDIT.md
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $blockTypes,
    ) {
    }
}
