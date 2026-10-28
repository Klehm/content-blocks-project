<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * The locales this installation translates into, and the one `data` is written
 * in. **The source locale is not a target** — that asymmetry lives here only.
 *
 * @see docs/internals/i18n.md#config-and-mounting
 *
 * @phpstan-type Loaded array{
 *     0: list<string>,
 *     1: array<string, list<string>>,
 * }
 */
final class TranslationLocales
{
    /** @var list<string>|null null until {@see self::$load} has run */
    private ?array $targets;

    /** @var array<string, list<string>>|null */
    private ?array $fallbacks;

    /** @var (\Closure(): Loaded)|null */
    private ?\Closure $load = null;

    /**
     * @param list<string>                $locales
     * @param array<string, string>       $labels    locale => host label
     * @param array<string, list<string>> $fallbacks locale => fallback chain
     */
    public function __construct(
        private readonly string $sourceLocale,
        array $locales,
        private readonly array $labels = [],
        array $fallbacks = [],
    ) {
        $this->targets = self::normalize($locales, $sourceLocale);
        $this->fallbacks = $fallbacks;
    }

    /**
     * The targets and chains read on first use: a provider querying the
     * database must not run while the container merely builds a service.
     *
     * @internal built by {@see TranslationLocalesFactory}
     *
     * @param \Closure(): Loaded     $load   targets, then fallback chains
     * @param array<string, string> $labels
     *
     * @see docs/internals/i18n.md#config-and-mounting
     */
    public static function deferred(string $sourceLocale, \Closure $load, array $labels = []): self
    {
        $locales = new self($sourceLocale, [], $labels);
        $locales->targets = null;
        $locales->fallbacks = null;
        $locales->load = $load;

        return $locales;
    }

    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    /** @return list<string> */
    public function getTargetLocales(): array
    {
        return $this->targets();
    }

    /**
     * Source first, then the targets in configured order.
     *
     * @return list<string>
     */
    public function getAllLocales(): array
    {
        return [$this->sourceLocale, ...$this->targets()];
    }

    public function isTarget(string $locale): bool
    {
        return \in_array($locale, $this->targets(), true);
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
     * The targets an untranslated field of `$locale` is read from, in order,
     * before the source. Empty unless the host configured `fallbacks`.
     *
     * @see docs/internals/i18n.md#the-fallback-chain
     *
     * @return list<string>
     */
    public function getFallbacks(string $locale): array
    {
        // A provider can drop a locale the config still names: skip it.
        return array_values(array_unique(array_filter(
            $this->chains()[$locale] ?? [],
            fn (string $fallback): bool => $fallback !== $locale && $this->isTarget($fallback),
        )));
    }

    /**
     * Configured label, else ext-intl, else the raw tag — the extension is
     * optional here, and `de` beats a fatal error.
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

    /** @return list<string> */
    private function targets(): array
    {
        $this->resolve();

        return $this->targets ?? [];
    }

    /** @return array<string, list<string>> */
    private function chains(): array
    {
        $this->resolve();

        return $this->fallbacks ?? [];
    }

    private function resolve(): void
    {
        if ($this->load === null) {
            return;
        }
        [$locales, $fallbacks] = ($this->load)();
        $this->load = null;
        $this->targets = self::normalize($locales, $this->sourceLocale);
        $this->fallbacks = $fallbacks;
    }

    /**
     * The source may or may not appear in the list; either spelling is
     * natural, so accept both.
     *
     * @param list<string> $locales
     *
     * @return list<string>
     */
    private static function normalize(array $locales, string $sourceLocale): array
    {
        return array_values(array_unique(array_filter(
            $locales,
            static fn (string $locale): bool => $locale !== $sourceLocale && $locale !== '',
        )));
    }
}
