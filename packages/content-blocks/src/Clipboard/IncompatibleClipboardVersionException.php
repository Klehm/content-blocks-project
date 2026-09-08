<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * Copied under a different `content_version`. Refused, not upgraded.
 *
 * @see docs/internals/clipboard.md#the-envelope
 */
final class IncompatibleClipboardVersionException extends \RuntimeException
{
    public function __construct(
        private readonly ?int $copiedVersion,
        private readonly int $currentVersion,
    ) {
        parent::__construct(sprintf(
            'Clipboard entry was copied under content version %s, this build runs %d.',
            $copiedVersion === null ? 'NULL' : (string) $copiedVersion,
            $currentVersion,
        ));
    }

    public function getCopiedVersion(): ?int
    {
        return $this->copiedVersion;
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }
}
