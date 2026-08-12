<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * What a provider produced for one request.
 *
 * Failure is a value, not an exception. Translating a page is a batch of
 * dozens of independent strings, and one that trips a rate limit or a content
 * filter must not discard the fifty that succeeded — the editor would have no
 * way to tell which half to redo. A provider that fails *entirely* (bad
 * credentials, unreachable host) is free to throw; per-item trouble belongs
 * here.
 */
final class TranslationOutcome
{
    private function __construct(
        public readonly string $path,
        public readonly ?string $text,
        public readonly ?string $error,
    ) {
    }

    public static function success(string $path, string $text): self
    {
        return new self($path, $text, null);
    }

    public static function failure(string $path, string $error): self
    {
        return new self($path, null, $error);
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->text !== null;
    }
}
