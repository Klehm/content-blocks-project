<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * Thrown when stored content carries a schema generation the app cannot use and
 * no {@see ContentVersionUpgraderInterface} knows how to bridge.
 *
 * Distinct from the two other hard stops on a section template, which are about
 * different things: {@see \ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException}
 * means the *envelope* structure (owned by this package) is unreadable, and
 * {@see \ContentBlocks\SectionTemplate\IncompatibleTemplateException} means every
 * block it references is gone. This one means the payload is readable and its
 * blocks exist, but the shape of their data belongs to another generation of the
 * host's own schema.
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
