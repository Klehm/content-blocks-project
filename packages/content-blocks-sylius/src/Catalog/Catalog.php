<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Catalog;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;

/**
 * What the catalog blocks show, read for the current channel and locale:
 * a disabled product or one sold elsewhere is simply left out.
 */
final class Catalog
{
    public const MAX_PRODUCTS = 24;

    /**
     * @param ProductRepositoryInterface<ProductInterface> $products
     * @param TaxonRepositoryInterface<TaxonInterface> $taxons
     */
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly TaxonRepositoryInterface $taxons,
        private readonly ChannelContextInterface $channels,
        private readonly LocaleContextInterface $locale,
        private readonly ProductVariantResolverInterface $variants,
    ) {
    }

    public function product(?string $code): ?ProductInterface
    {
        $channel = $this->channel();
        if ($channel === null || $code === null || $code === '') {
            return null;
        }

        return $this->products->findOneByChannelAndCode($channel, $code);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<ProductInterface>
     */
    public function productsByCode(array $codes): array
    {
        $found = [];
        foreach (array_slice(array_unique($codes), 0, self::MAX_PRODUCTS) as $code) {
            $product = $this->product((string) $code);
            if ($product !== null) {
                $found[] = $product;
            }
        }

        return $found;
    }

    /** @return list<ProductInterface> */
    public function latest(int $limit): array
    {
        $channel = $this->channel();
        if ($channel === null) {
            return [];
        }

        return array_values($this->products->findLatestByChannel($channel, $this->locale(), self::limit($limit)));
    }

    /** @return list<ProductInterface> */
    public function inTaxon(?string $code, int $limit): array
    {
        $channel = $this->channel();
        $taxon = $this->taxon($code);
        if ($channel === null || $taxon === null) {
            return [];
        }

        $products = $this->products
            ->createShopListQueryBuilder($channel, $taxon, $this->locale(), [], true)
            ->setMaxResults(self::limit($limit))
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            \is_array($products) ? $products : [],
            static fn ($product): bool => $product instanceof ProductInterface,
        ));
    }

    public function variant(ProductInterface $product): ?ProductVariantInterface
    {
        $variant = $this->variants->getVariant($product);

        return $variant instanceof ProductVariantInterface ? $variant : null;
    }

    public function taxon(?string $code): ?TaxonInterface
    {
        if ($code === null || $code === '') {
            return null;
        }
        $taxon = $this->taxons->findOneBy(['code' => $code]);

        return $taxon instanceof TaxonInterface && $taxon->isEnabled() ? $taxon : null;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<TaxonInterface>
     */
    public function taxonsByCode(array $codes): array
    {
        return array_values(array_filter(array_map($this->taxon(...), array_unique($codes))));
    }

    /** @return list<TaxonInterface> */
    public function children(?string $code): array
    {
        $parent = $this->taxon($code);
        if ($parent === null) {
            return [];
        }

        $children = [];
        foreach ($parent->getChildren() as $child) {
            if ($child instanceof TaxonInterface && $child->isEnabled()) {
                $children[] = $child;
            }
        }

        return $children;
    }

    public function channel(): ?ChannelInterface
    {
        try {
            $channel = $this->channels->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }

        return $channel instanceof ChannelInterface ? $channel : null;
    }

    public function locale(): string
    {
        return $this->locale->getLocaleCode();
    }

    private static function limit(int $limit): int
    {
        return max(1, min(self::MAX_PRODUCTS, $limit));
    }
}
