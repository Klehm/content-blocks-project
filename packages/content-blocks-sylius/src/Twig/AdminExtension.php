<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Twig;

use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Routing\ShopUrls;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Links from the admin to the shop. */
final class AdminExtension extends AbstractExtension
{
    public function __construct(private readonly ShopUrls $urls)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cb_sylius_page_urls', $this->pageUrls(...))];
    }

    /** @return array<string, string> locale => shop URL, where it has a slug */
    public function pageUrls(?ContentPage $page): array
    {
        if ($page === null || $page->getId() === null) {
            return [];
        }
        $urls = [];
        foreach ($page->getTranslations() as $locale => $translation) {
            $url = $this->urls->page($page, (string) $locale);
            if ($url !== null) {
                $urls[(string) $locale] = $url;
            }
        }

        return $urls;
    }
}
