<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * Default {@see ContentVersionUpgraderInterface}: refuses a generation it is not
 * told how to bridge, because the package cannot know what changed between two
 * of the host's own versions.
 *
 * Two rules, and the asymmetry between them is deliberate:
 *
 *  - a **known** mismatch (stored 3, current 4) is refused. Something changed
 *    between the two and only the host knows what; replaying the payload blind
 *    is how content quietly rots.
 *  - an **unknown** version (`null`) is accepted. Every row written before
 *    versioning existed carries null, so refusing it would make a host's entire
 *    section-template library unusable the day they upgrade — a regression far
 *    worse than the risk it guards against. Null means "no information", not
 *    "wrong".
 *
 * A host that wants the strict reading (refuse null too), or that can migrate a
 * payload on read, aliases the interface to its own implementation.
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
