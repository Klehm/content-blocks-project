<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

/**
 * Everything a publish or discard needs to know beyond the area itself.
 *
 * It exists for the same reason {@see \ContentBlocks\Rendering\RenderContext}
 * does: the publish methods used to take nothing but a `ContentArea`, and
 * adding a parameter to a published interface breaks every implementor —
 * including a host's own decorator wrapping it for an audit trail or a cache
 * purge. A context object can grow fields without touching the signature, so
 * the seam is frozen with room in it.
 *
 * ---- What the locale scope means ----
 *
 * The core has no notion of locales: an area's own draft is a single state,
 * and {@see ContentAreaPublisher} promotes it whatever the context says.
 * The scope addresses **translations**, and a satellite package that decorates
 * the publisher is what reads it. With `klehm/content-blocks-i18n` installed:
 *
 *  - `null` (the default, and {@see self::everything()}) — the area's draft and
 *    every locale's translations, which is what Publish has always done.
 *  - {@see self::withLocales()} — the area's draft, plus only the named
 *    locales. Every other locale keeps its currently published values, so a
 *    finished French can go live while German is still being written.
 *  - {@see self::sourceOnly()} — the area's draft alone. Translations stay as
 *    published; the ones whose source just moved show up as outdated, which is
 *    what the staleness digests are for.
 *
 * ---- The invariant this shape enforces ----
 *
 * There is deliberately no way to publish a locale *without* publishing the
 * area's pending draft. A translation is written against a specific source
 * text, so pushing it ahead of that text is the one ordering that produces the
 * failure the feature exists to prevent: a French heading live on the public
 * site describing an English heading nobody has seen. Holding a translation
 * back is safe and expressible; running it ahead of its source is neither.
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

    /** Whether any locale at all is in scope — false for {@see self::sourceOnly()}. */
    public function coversAnyLocale(): bool
    {
        return $this->locales === null || $this->locales !== [];
    }
}
