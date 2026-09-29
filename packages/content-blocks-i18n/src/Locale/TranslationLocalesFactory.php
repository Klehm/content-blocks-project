<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * Builds the {@see TranslationLocales} service from the host's providers.
 *
 * @internal
 *
 * @see docs/internals/i18n.md#config-and-mounting
 */
final class TranslationLocalesFactory
{
    /**
     * @param array<string, string> $labels locale => label from the config
     */
    public static function create(
        string $sourceLocale,
        TargetLocalesProviderInterface $provider,
        array $labels = [],
        ?LocaleFallbacksProviderInterface $fallbacks = null,
    ): TranslationLocales {
        $chains = [];

        // A host provider may return one code where a list is expected.
        foreach ($fallbacks?->getFallbacks() ?? [] as $locale => $chain) {
            $chains[(string) $locale] = array_values(array_filter(
                (array) $chain,
                static fn ($code): bool => \is_string($code) && $code !== '',
            ));
        }

        return new TranslationLocales($sourceLocale, $provider->getTargetLocales(), $labels, $chains);
    }
}
