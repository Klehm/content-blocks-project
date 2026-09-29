<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * Supplies each locale's fallback chain. Alias it to derive the chains from
 * where the host keeps its locales; the default reads `fallbacks`.
 *
 * @see docs/guide/translation.md#fallbacks-from-the-host
 */
interface LocaleFallbacksProviderInterface
{
    /**
     * Locale => the targets its untranslated fields are read from, in order.
     * Called once per container, like the target locales. Keep it cheap.
     *
     * @return array<string, list<string>>
     */
    public function getFallbacks(): array;
}
