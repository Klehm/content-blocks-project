<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Thrown when a stored template payload does not carry the envelope format the
 * current instantiator can read.
 *
 * Separate from {@see IncompatibleTemplateException} on purpose: that one means
 * "the blocks this template references are gone", and carries the missing type
 * ids. A format mismatch is about the payload's own structure — its
 * `getMissingTypes()` would be empty, which would read as "nothing is missing".
 *
 * Both are hard stops with the same consequence for the editor (the template
 * cannot be inserted), so the controller answers 422 for either.
 */
final class UnsupportedTemplateFormatException extends \RuntimeException
{
    public function __construct(
        private readonly ?string $found,
        private readonly string $expected,
    ) {
        parent::__construct(sprintf(
            'Section template payload has format %s (expected %s).',
            $found === null ? '(none)' : '"' . $found . '"',
            '"' . $expected . '"',
        ));
    }

    /** Format read from the payload, or null when the key was absent/invalid. */
    public function getFound(): ?string
    {
        return $this->found;
    }

    public function getExpected(): string
    {
        return $this->expected;
    }
}
