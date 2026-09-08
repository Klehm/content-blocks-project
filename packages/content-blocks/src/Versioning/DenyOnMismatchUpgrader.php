<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * Default {@see ContentVersionUpgraderInterface}: refuses a known mismatch,
 * accepts an unknown (`null`) version. The asymmetry is deliberate.
 *
 * @see docs/internals/versioning.md#why-the-default-refuses-a-known-mismatch
 */
final class DenyOnMismatchUpgrader implements ContentVersionUpgraderInterface
{
    public function supports(?int $stored, int $current): bool
    {
        return $stored === null || $stored === $current;
    }

    public function upgrade(array $payload, ?int $stored, int $current): array
    {
        if (!$this->supports($stored, $current)) {
            throw new IncompatibleContentVersionException($stored, $current);
        }

        return $payload;
    }
}
