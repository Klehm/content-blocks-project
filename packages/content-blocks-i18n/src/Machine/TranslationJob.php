<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * Settings a batch is translated under, separate from the requests so a knob
 * can be added without changing the interface. `glossary`/`tone` are advisory.
 */
final class TranslationJob
{
    /**
     * @param array<string, string> $glossary term => required translation
     * @param string|null           $tone     free-text style instruction
     */
    public function __construct(
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly array $glossary = [],
        public readonly ?string $tone = null,
    ) {
    }
}
