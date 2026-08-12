<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * The clipboard entry is not something this build can read: a foreign format,
 * an unknown scope, or a payload that is not even a structure. Hand-editing
 * `localStorage` is the likely cause, so the message stays generic.
 */
final class UnreadableClipboardException extends \RuntimeException
{
    public function __construct(private readonly string $part)
    {
        parent::__construct(sprintf('Unreadable clipboard entry (%s).', $part));
    }

    /** Which part of the envelope failed — `format`, `scope` or `payload`. */
    public function getPart(): string
    {
        return $this->part;
    }
}
