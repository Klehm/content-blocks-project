<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * What a machine-translation run did — per field, not as a single verdict.
 *
 * A page translation touches dozens of independent strings and some of them
 * will fail: a rate limit halfway through, one string the engine refuses. The
 * editor needs to know which, so the report is a list and not a boolean.
 */
final class TranslationRunResult
{
    /**
     * @param list<string>          $translated field refs written
     * @param array<string, string> $failed     field ref => error
     * @param int                   $skipped    fields already translated and up to date
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $provider,
        public readonly array $translated = [],
        public readonly array $failed = [],
        public readonly int $skipped = 0,
    ) {
    }

    public function getTranslatedCount(): int
    {
        return \count($this->translated);
    }

    public function getFailedCount(): int
    {
        return \count($this->failed);
    }

    public function isEmpty(): bool
    {
        return $this->translated === [] && $this->failed === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'provider' => $this->provider,
            'translated' => $this->getTranslatedCount(),
            'failed' => $this->failed,
            'skipped' => $this->skipped,
        ];
    }
}
