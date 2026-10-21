<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Routing;

use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentSlot;
use ContentBlocks\Sylius\Slot\SlotType;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Resource\Model\TranslatableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The shop address of a page or a slot in one locale, null where it has
 * none: a page or a product without a slug in that locale.
 */
final class ShopUrls
{
    public const PAGE_ROUTE = 'content_blocks_sylius_shop_page_show';

    /**
     * @param ProductRepositoryInterface<ProductInterface> $products
     * @param TaxonRepositoryInterface<TaxonInterface> $taxons
     */
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly ProductRepositoryInterface $products,
        private readonly TaxonRepositoryInterface $taxons,
    ) {
    }

    public function page(ContentPage $page, string $locale, bool $absolute = false): ?string
    {
        $slug = $page->getSlugIn($locale);

        return $slug === null ? null : $this->generate(self::PAGE_ROUTE, $slug, $locale, $absolute);
    }

    public function slot(ContentSlot $slot, string $locale, bool $absolute = false): ?string
    {
        if ($slot->getType() === SlotType::HOMEPAGE) {
            $type = $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH;

            return $this->router->generate('sylius_shop_homepage', ['_locale' => $locale], $type);
        }

        $subject = $slot->getType() === SlotType::PRODUCT
            ? $this->products->findOneByCode($slot->getSubject())
            : $this->taxons->findOneBy(['code' => $slot->getSubject()]);
        $slug = self::slugIn($subject, $locale);
        if ($slug === null) {
            return null;
        }
        $route = $slot->getType() === SlotType::PRODUCT ? 'sylius_shop_product_show' : 'sylius_shop_product_index';

        return $this->generate($route, $slug, $locale, $absolute);
    }

    private function generate(string $route, string $slug, string $locale, bool $absolute): string
    {
        return $this->router->generate(
            $route,
            ['slug' => $slug, '_locale' => $locale],
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }

    private static function slugIn(?object $subject, string $locale): ?string
    {
        if (!$subject instanceof TranslatableInterface || !$subject->getTranslations()->containsKey($locale)) {
            return null;
        }
        $translation = $subject->getTranslation($locale);
        $slug = method_exists($translation, 'getSlug') ? $translation->getSlug() : null;

        return \is_string($slug) && $slug !== '' ? $slug : null;
    }
}
