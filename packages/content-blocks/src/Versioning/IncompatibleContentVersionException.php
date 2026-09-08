<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * The payload is readable and its blocks exist, but their data belongs to
 * another generation of the host's schema. One of three distinct hard stops.
 *
 * @see docs/internals/versioning.md#three-hard-stops-three-different-meanings
 */
final class IncompatibleContentVersionException extends \RuntimeException
{
    public function __construct(
        private readonly ?int $stored,
        private readonly int $current,
    ) {
        parent::__construct(sprintf(
            'Stored content is at schema generation %s, the app runs %d, and no upgrader bridges the two.',
            $stored === null ? '(unknown)' : (string) $stored,
            $current,
        ));
    }

    /** Null when the content predates versioning. */
    public function getStoredVersion(): ?int
    {
        return $this->stored;
    }

    public function getCurrentVersion(): int
    {
        return $this->current;
    }
}
