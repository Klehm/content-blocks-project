<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

/**
 * What a publish or discard needs beyond the area. A context object so the
 * frozen seam can grow fields; the core ignores the locale scope.
 *
 * @see docs/internals/publishing.md#why-publish-takes-a-context-object
 */
final class PublishContext
{
    /**
     * @param list<string>|null $locales null = every locale
     */
    private function __construct(
        public readonly ?array $locales = null,
    ) {
    }

    /** The area's draft and every locale's translations. */
    public static function everything(): self
    {
        return new self(null);
    }

    /** The area's draft, plus only the named locales' translations. */
    public static function withLocales(string ...$locales): self
    {
        return new self(array_values(array_unique($locales)));
    }

    /** The area's draft alone; every translation stays as it is. */
    public static function sourceOnly(): self
    {
        return new self([]);
    }

    /** Whether this context covers the given locale's translations. */
    public function coversLocale(string $locale): bool
    {
        return $this->locales === null || in_array($locale, $this->locales, true);
    }

    /** Whether any locale is in scope — false for {@see self::sourceOnly()} */
    public function coversAnyLocale(): bool
    {
        return $this->locales === null || $this->locales !== [];
    }
}
