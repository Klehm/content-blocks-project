<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Storage;

/**
 * Outcome of a write, reporting per-path rather than as one boolean.
 *
 * Same principle the clipboard replayer follows: a payload that is partly
 * unacceptable costs the unacceptable part, not the whole operation. A
 * workbench that autosaves ten fields must not lose nine because one addresses
 * a card the editor deleted in another tab.
 */
final class TranslationWriteResult
{
    /**
     * @param list<string>          $written  paths that now hold a translation
     * @param list<string>          $cleared  paths whose translation was removed
     * @param array<string, string> $rejected path => reason
     */
    public function __construct(
        public readonly array $written = [],
        public readonly array $cleared = [],
        public readonly array $rejected = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->written === [] && $this->cleared === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'written' => $this->written,
            'cleared' => $this->cleared,
            'rejected' => $this->rejected,
        ];
    }
}
