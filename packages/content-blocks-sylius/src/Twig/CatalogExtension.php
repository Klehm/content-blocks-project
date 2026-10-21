<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Twig;

use ContentBlocks\Sylius\Catalog\Catalog;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The catalog data the catalog blocks' views read, from their block data. */
final class CatalogExtension extends AbstractExtension
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_sylius_product', $this->catalog->product(...)),
            new TwigFunction('cb_sylius_variant', $this->catalog->variant(...)),
            new TwigFunction('cb_sylius_product_list', $this->productList(...)),
            new TwigFunction('cb_sylius_taxon_list', $this->taxonList(...)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ProductInterface>
     */
    public function productList(array $data): array
    {
        $limit = is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 4;

        return match ($data['source'] ?? null) {
            'manual' => $this->catalog->productsByCode(self::codes($data['products'] ?? [])),
            'taxon' => $this->catalog->inTaxon(self::code($data['taxon'] ?? null), $limit),
            'latest' => $this->catalog->latest($limit),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<TaxonInterface>
     */
    public function taxonList(array $data): array
    {
        return ($data['source'] ?? null) === 'manual'
            ? $this->catalog->taxonsByCode(self::codes($data['taxons'] ?? []))
            : $this->catalog->children(self::code($data['parent'] ?? null));
    }

    /** @return list<string> */
    private static function codes(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function code(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
