<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Preview;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Locale\ShopLocales;
use ContentBlocks\Sylius\Owner\AreaOwnerLocator;
use ContentBlocks\Sylius\Routing\ShopUrls;

/**
 * The shop page showing an area, in the shop's default locale when it has an
 * address there, else in the first locale that has one.
 */
final class ShopContentAreaUrlResolver implements ContentAreaUrlResolverInterface
{
    public function __construct(
        private readonly AreaOwnerLocator $owners,
        private readonly ShopUrls $urls,
        private readonly ShopLocales $locales,
    ) {
    }

    public function resolve(ContentArea $area): string
    {
        $owner = $this->owners->locate($area);
        if ($owner === null) {
            $message = 'Content area #%s belongs to no page or slot of the Sylius plugin: '
                . 'alias %s to a resolver that knows its owner.';

            throw new \LogicException(sprintf($message, (string) $area->getId(), ContentAreaUrlResolverInterface::class));
        }

        foreach ($this->locales->codes() as $locale) {
            $url = $owner instanceof ContentPage
                ? $this->urls->page($owner, $locale)
                : $this->urls->slot($owner, $locale);
            if ($url !== null) {
                return $url;
            }
        }

        throw new \LogicException(sprintf('Content area #%s has no shop address in any locale yet.', (string) $area->getId()));
    }
}
