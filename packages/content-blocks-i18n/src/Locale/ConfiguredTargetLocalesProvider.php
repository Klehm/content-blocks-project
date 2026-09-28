<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * The default target locales: the codes of `content_blocks_i18n.locales`.
 */
final class ConfiguredTargetLocalesProvider implements TargetLocalesProviderInterface
{
    /**
     * @param list<string> $locales
     */
    public function __construct(private readonly array $locales)
    {
    }

    public function getTargetLocales(): array
    {
        return $this->locales;
    }
}
