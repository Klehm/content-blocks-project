<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * One step in migrating a payload's **envelope** — the structure this package
 * owns, as opposed to the content the host owns. Autoconfigured.
 *
 * @see docs/internals/versioning.md#the-ownership-line
 */
interface EnvelopeUpgraderInterface
{
    /** Format string this step reads, e.g. `content-blocks/section-v1`. */
    public function upgradesFrom(): string;

    /** Format string it produces, e.g. `content-blocks/section-v2`. */
    public function upgradesTo(): string;

    /**
     * Only the envelope is this step's business — the block data inside it
     * must be carried over as-is.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function upgrade(array $payload): array;
}
