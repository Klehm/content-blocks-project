<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * The entry was copied under a different `content_blocks.content_version` than
 * the one this build runs. Refused rather than upgraded — see
 * {@see ClipboardEnvelope} for why the clipboard makes that trade.
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
