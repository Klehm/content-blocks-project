<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Storage;

/**
 * Per-path rather than one boolean: a partly unacceptable payload costs the
 * unacceptable part, not the whole operation.
 */
final class TranslationWriteResult
{
    /**
     * @param list<string>          $written  now holding a translation
     * @param list<string>          $cleared  translation removed
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
