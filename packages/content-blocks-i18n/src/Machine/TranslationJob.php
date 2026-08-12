<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * The settings a batch of requests is translated under.
 *
 * Separate from the requests so a provider can configure itself once per call
 * rather than per string — and so adding a knob later (formality, a domain
 * hint, a translation memory id) does not change
 * {@see TranslationProviderInterface}'s signature.
 *
 * `$glossary` and `$tone` are advisory: a provider that cannot honor them
 * ignores them rather than failing. Brand names that must never be translated
 * are the case that keeps coming up in practice, which is why the glossary is
 * here rather than left to each provider's own configuration.
 */
final class TranslationJob
{
    /**
     * @param array<string, string> $glossary source term => required translation
     * @param string|null           $tone     free-text style instruction, e.g. "formal, address the reader as Sie"
     */
    public function __construct(
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly array $glossary = [],
        public readonly ?string $tone = null,
    ) {
    }
}
