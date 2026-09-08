<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Thrown when a stored payload does not carry an envelope format this
 * instantiator can read. Distinct from {@see IncompatibleTemplateException}.
 *
 * @see docs/internals/section-templates.md#two-ways-a-template-fails-to-read
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

    /** Format read from the payload; null when absent or invalid. */
    public function getFound(): ?string
    {
        return $this->found;
    }

    public function getExpected(): string
    {
        return $this->expected;
    }
}
