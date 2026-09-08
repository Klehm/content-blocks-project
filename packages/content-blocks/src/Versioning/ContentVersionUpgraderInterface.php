<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * What to do with stored content from another of the host's own schema
 * generations. Section templates only, and the upgrade is transient.
 *
 * @see docs/internals/versioning.md#the-content-version
 */
interface ContentVersionUpgraderInterface
{
    /**
     * Cheap, side-effect-free predicate, called once per row when listing the
     * library. {@see upgrade()} does the work.
     *
     * @param int|null $stored  null means the row predates versioning
     * @param int      $current the configured content_version
     */
    public function supports(?int $stored, int $current): bool;

    /**
     * Bring a payload up to the current generation, or refuse it. Only called
     * for payloads {@see supports()} accepted, so throwing is legitimate.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> as the current generation expects it
     *
     * @throws IncompatibleContentVersionException when the gap is unbridgeable
     */
    public function upgrade(array $payload, ?int $stored, int $current): array;
}
