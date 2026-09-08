<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * The locales this installation translates into, and the one `data` is written
 * in. **The source locale is not a target** — that asymmetry lives here only.
 *
 * @see docs/internals/i18n.md#config-and-mounting
 */
final class TranslationLocales
{
    /** @var list<string> */
    private readonly array $targets;

    /**
     * @param list<string>          $locales
     * @param array<string, string> $labels  locale => host-supplied label
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

    /**
     * Source first, then the targets in configured order.
     *
     * @return list<string>
     */
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
}
