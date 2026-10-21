<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

/** One product, as the shop's own product card. */
#[AsContentBlock(priority: 100, category: 'commerce')]
class ProductBlock extends AbstractCatalogBlock
{
    public function getType(): string
    {
        return 'product';
    }

    public function getPurpose(): string
    {
        return 'One product of the catalog shown as a card: image, name, price, '
            . 'optionally an add-to-cart form. Use it to feature a single product.';
    }

    public function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M20 7l-8-4-8 4 8 4 8-4z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $this->addProductField($builder, 'product');
        $builder->add('addToCart', CheckboxType::class, [
            'label' => 'content_blocks_sylius.field.add_to_cart',
            'required' => false,
        ]);
    }

    public function getDefaultData(): array
    {
        return ['product' => '', 'addToCart' => false];
    }
}
