<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * Supplies the locales editors translate into. Alias it to read them from
 * where the host keeps them; the default reads `content_blocks_i18n.locales`.
 *
 * @see docs/guide/translation.md#locales-from-the-host
 */
interface TargetLocalesProviderInterface
{
    /**
     * May include the source locale, which is dropped. Called once per
     * container: per request under PHP-FPM, once per worker. Keep it cheap.
     *
     * @return list<string>
     */
    public function getTargetLocales(): array;
}
