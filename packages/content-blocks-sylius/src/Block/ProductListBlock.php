<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockAssets;
use ContentBlocks\BlockType\BlockAssetsInterface;
use ContentBlocks\Sylius\Catalog\Catalog;
use ContentBlocks\Twig\RoutingExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Several products as a grid or a carousel: chosen, a taxon's, the latest. */
#[AsContentBlock(priority: 90, category: 'commerce')]
class ProductListBlock extends AbstractCatalogBlock implements BlockAssetsInterface
{
    public const SOURCES = ['manual', 'taxon', 'latest'];
    public const LAYOUTS = ['grid', 'carousel'];

    public function __construct(private readonly RoutingExtension $routing)
    {
    }

    public function getType(): string
    {
        return 'product_list';
    }

    public function getPurpose(): string
    {
        return 'A row of product cards: products picked by hand, the products of '
            . 'a taxon, or the latest ones. Grid or horizontally scrolling carousel.';
    }

    public function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="4" width="5" height="7" rx="1"/><rect x="10" y="4" width="5" height="7" rx="1"/>'
            . '<rect x="17" y="4" width="4" height="7" rx="1"/><path d="M3 15h5M10 15h5M17 15h4M3 19h3M10 19h3"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('source', ChoiceType::class, self::choiceOptions('content_blocks_sylius.field.source', [
            'content_blocks_sylius.source.manual' => 'manual',
            'content_blocks_sylius.source.taxon' => 'taxon',
            'content_blocks_sylius.source.latest' => 'latest',
        ]) + ['constraints' => [new Assert\Choice(choices: self::SOURCES)]]);
        $this->addProductField($builder, 'products', true, ['row_attr' => ['data-cb-condition' => 'source:manual']]);
        $this->addTaxonField($builder, 'taxon', false, ['row_attr' => ['data-cb-condition' => 'source:taxon']]);
        $builder
            ->add('limit', IntegerType::class, [
                'label' => 'content_blocks_sylius.field.limit',
                'row_attr' => ['data-cb-condition' => 'source:taxon|latest'],
                'constraints' => [new Assert\Range(min: 1, max: Catalog::MAX_PRODUCTS)],
            ])
            ->add('layout', ChoiceType::class, self::choiceOptions('content_blocks_sylius.field.layout', [
                'content_blocks_sylius.layout.grid' => 'grid',
                'content_blocks_sylius.layout.carousel' => 'carousel',
            ]) + ['constraints' => [new Assert\Choice(choices: self::LAYOUTS)]])
            ->add('columns', ChoiceType::class, [
                'label' => 'content_blocks_sylius.field.columns',
                'choices' => ['2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6],
                'choice_translation_domain' => false,
                'constraints' => [new Assert\Range(min: 2, max: 6)],
            ]);
    }

    /** The carousel borrows the core's section slider for its controls. */
    public function assets(array $data): BlockAssets
    {
        return ($data['layout'] ?? 'grid') === 'carousel'
            ? new BlockAssets(modules: [$this->routing->assetPath('slider')])
            : BlockAssets::none();
    }

    public function getDefaultData(): array
    {
        return [
            'source' => 'latest',
            'products' => [],
            'taxon' => '',
            'limit' => 4,
            'layout' => 'grid',
            'columns' => 4,
        ];
    }
}
