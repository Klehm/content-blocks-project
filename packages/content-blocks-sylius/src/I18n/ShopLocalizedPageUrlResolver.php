<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\I18n;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Owner\AreaOwnerLocator;
use ContentBlocks\Sylius\Routing\ShopUrls;

/** Absolute shop URL of an area's page in a locale, for hreflang. */
final class ShopLocalizedPageUrlResolver implements LocalizedPageUrlResolverInterface
{
    public function __construct(
        private readonly AreaOwnerLocator $owners,
        private readonly ShopUrls $urls,
    ) {
    }

    public function resolve(ContentArea $area, string $locale): ?string
    {
        $owner = $this->owners->locate($area);

        return match (true) {
            $owner instanceof ContentPage => $this->urls->page($owner, $locale, true),
            $owner !== null => $this->urls->slot($owner, $locale, true),
            default => null,
        };
    }
}
