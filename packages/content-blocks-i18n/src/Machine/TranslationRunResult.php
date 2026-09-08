<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * What a run did, per field rather than as one verdict — the editor needs to
 * know *which* strings failed.
 */
final class TranslationRunResult
{
    /**
     * @param list<string>          $translated field refs written
     * @param array<string, string> $failed     field ref => error
     * @param int                   $skipped    already up to date
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
