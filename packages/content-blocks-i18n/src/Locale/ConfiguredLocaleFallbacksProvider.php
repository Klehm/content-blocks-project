<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

/**
 * The default fallback chains: `content_blocks_i18n.fallbacks`, empty unless
 * configured.
 */
final class ConfiguredLocaleFallbacksProvider implements LocaleFallbacksProviderInterface
{
    /**
     * @param array<string, list<string>> $fallbacks
     */
    public function __construct(private readonly array $fallbacks)
    {
    }

    public function getFallbacks(): array
    {
        return $this->fallbacks;
    }
}
