<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** A product's price in the channel, struck-through original price included. */
#[AsContentBlock(priority: 80, category: 'commerce')]
class ProductPriceBlock extends AbstractCatalogBlock
{
    public const SIZES = ['md', 'lg'];

    public function getType(): string
    {
        return 'product_price';
    }

    public function getPurpose(): string
    {
        return 'The current price of one product in the shop\'s channel and '
            . 'currency, with the original price when a promotion applies.';
    }

    public function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/>'
            . '<circle cx="7.5" cy="7.5" r="1.5"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $this->addProductField($builder, 'product');
        $builder->add('size', ChoiceType::class, self::choiceOptions('content_blocks_sylius.field.size', [
            'content_blocks_sylius.size.md' => 'md',
            'content_blocks_sylius.size.lg' => 'lg',
        ]) + ['constraints' => [new Assert\Choice(choices: self::SIZES)]]);
    }

    public function getDefaultData(): array
    {
        return ['product' => '', 'size' => 'lg'];
    }
}
