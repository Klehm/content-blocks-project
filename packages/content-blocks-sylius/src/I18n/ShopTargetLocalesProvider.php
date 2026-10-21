<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\I18n;

use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\Sylius\Locale\ShopLocales;

/** The shop's locales are the translation targets, the source left out. */
final class ShopTargetLocalesProvider implements TargetLocalesProviderInterface
{
    public function __construct(
        private readonly ShopLocales $locales,
        private readonly string $sourceLocale,
    ) {
    }

    public function getTargetLocales(): array
    {
        return array_values(array_filter(
            $this->locales->codes(),
            fn (string $code): bool => $code !== $this->sourceLocale,
        ));
    }
}
