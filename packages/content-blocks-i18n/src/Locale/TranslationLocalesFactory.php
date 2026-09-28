<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * Builds the {@see TranslationLocales} service from the host's provider.
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
    ): TranslationLocales {
        return new TranslationLocales($sourceLocale, $provider->getTargetLocales(), $labels);
    }
}
