<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * One step in migrating a stored payload's **envelope** — the structure this
 * package owns (`{format, contentArea: {sections: […]}, assets}` for a transfer,
 * `{format, layout, settings, columns}` for a section template).
 *
 * This is the counterpart of {@see ContentVersionUpgraderInterface}, on the other
 * side of the ownership line: the *content* inside a payload belongs to the block
 * types, so migrating it is the host's job; the envelope around it belongs to
 * this package, so migrating it is ours.
 *
 * Without such steps, bumping an envelope format would condemn every stored
 * section template and every exported file — which in practice means the format
 * could never be bumped at all. A step ships alongside the change that makes it
 * necessary, and old payloads keep working.
 *
 * Implementations are autoconfigured (tag `content_blocks.envelope_upgrader`) and
 * chained by {@see EnvelopeUpgradeChain}, which walks from a payload's declared
 * format to the one the code reads today. Steps are ordinary services, so a host
 * may add its own for a format it invented.
 */
interface EnvelopeUpgraderInterface
{
    /** Format string this step reads, e.g. `content-blocks/section-v1`. */
    public function upgradesFrom(): string;

    /** Format string it produces, e.g. `content-blocks/section-v2`. */
    public function upgradesTo(): string;

    /**
     * Restructure the payload. Only the envelope is this step's business — block
     * data inside it belongs to the block types and must be carried over as-is.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function upgrade(array $payload): array;
}
