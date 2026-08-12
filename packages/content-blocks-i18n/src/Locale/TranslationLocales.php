<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * The set of locales this installation translates into, and which one the
 * blocks' own `data` is written in.
 *
 * The **source locale is not a target**. A block's `data` *is* the source text —
 * there is no translation row for it, nothing to fall back to, and asking for a
 * progress percentage on it is meaningless. Keeping that asymmetry in one object
 * stops it from being re-derived (differently) in the resolver, the progress
 * calculator and the workbench.
 *
 * Built from `content_blocks_i18n.source_locale` / `.locales`; injected as a
 * value object rather than read from parameters at each call site so tests can
 * hand over a set without a container.
 */
final class TranslationLocales
{
    /** @var list<string> */
    private readonly array $targets;

    /**
     * @param array<string, string> $labels locale => display label, for the ones the host named
     */
    public function __construct(
        private readonly string $sourceLocale,
        array $locales,
        private readonly array $labels = [],
    ) {
        // The source may or may not appear in the configured list; either
        // spelling is natural, so accept both and normalize here.
        $this->targets = array_values(array_unique(array_filter(
            $locales,
            fn (string $locale): bool => $locale !== $sourceLocale && $locale !== '',
        )));
    }

    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    /** @return list<string> */
    public function getTargetLocales(): array
    {
        return $this->targets;
    }

    /** Source first, then the targets in configured order. @return list<string> */
    public function getAllLocales(): array
    {
        return [$this->sourceLocale, ...$this->targets];
    }

    public function isTarget(string $locale): bool
    {
        return \in_array($locale, $this->targets, true);
    }

    public function isSource(string $locale): bool
    {
        return $locale === $this->sourceLocale;
    }

    public function isKnown(string $locale): bool
    {
        return $this->isSource($locale) || $this->isTarget($locale);
    }

    /**
     * Display name: the host's configured label, else whatever ext-intl knows,
     * else the raw tag. The last fallback matters — the extension is optional in
     * this package's `require`, and a locale picker that renders `de` is worse
     * than one that renders "Deutsch" but far better than a fatal error.
     */
    public function getLabel(string $locale): string
    {
        if (isset($this->labels[$locale]) && $this->labels[$locale] !== '') {
            return $this->labels[$locale];
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName($locale, $locale);

            if (\is_string($name) && $name !== '' && $name !== $locale) {
                return ucfirst($name);
            }
        }

        return $locale;
    }

    /**
     * The whole set as the UI consumes it.
     *
     * @return list<array{code: string, label: string, source: bool}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (string $locale): array => [
                'code' => $locale,
                'label' => $this->getLabel($locale),
                'source' => $this->isSource($locale),
            ],
            $this->getAllLocales(),
        );
    }
}
