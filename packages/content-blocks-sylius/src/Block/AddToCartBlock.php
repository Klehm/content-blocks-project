<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\FormBuilderInterface;

/** The shop's own add-to-cart form of a product: variants, quantity, button. */
#[AsContentBlock(priority: 70, category: 'commerce')]
class AddToCartBlock extends AbstractCatalogBlock
{
    public function getType(): string
    {
        return 'add_to_cart';
    }

    public function getPurpose(): string
    {
        return 'The add-to-cart form of one product, with its variant choice and '
            . 'quantity. Place it next to text or images presenting that product.';
    }

    public function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/>'
            . '<path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h9.6a1 1 0 0 0 1-.8L21 7H6"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $this->addProductField($builder, 'product');
    }

    public function getDefaultData(): array
    {
        return ['product' => ''];
    }
}
