<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Block;

use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Category tiles linking to the taxon listings. */
#[AsContentBlock(priority: 60, category: 'commerce')]
class TaxonListBlock extends AbstractCatalogBlock
{
    public const SOURCES = ['children', 'manual'];

    public function getType(): string
    {
        return 'taxon_list';
    }

    public function getPurpose(): string
    {
        return 'Tiles linking to category pages: the sub-categories of a taxon, '
            . 'or taxons picked by hand. Each tile shows the taxon image and name.';
    }

    public function getIcon(): ?string
    {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>'
            . '<rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('source', ChoiceType::class, self::choiceOptions('content_blocks_sylius.field.source', [
            'content_blocks_sylius.source.children' => 'children',
            'content_blocks_sylius.source.manual_taxons' => 'manual',
        ]) + ['constraints' => [new Assert\Choice(choices: self::SOURCES)]]);
        $this->addTaxonField($builder, 'parent', false, [
            'label' => 'content_blocks_sylius.field.parent_taxon',
            'row_attr' => ['data-cb-condition' => 'source:children'],
        ]);
        $this->addTaxonField($builder, 'taxons', true, ['row_attr' => ['data-cb-condition' => 'source:manual']]);
        $builder->add('columns', ChoiceType::class, [
            'label' => 'content_blocks_sylius.field.columns',
            'choices' => ['2' => 2, '3' => 3, '4' => 4, '6' => 6],
            'choice_translation_domain' => false,
            'constraints' => [new Assert\Choice(choices: [2, 3, 4, 6])],
        ]);
    }

    public function getDefaultData(): array
    {
        return ['source' => 'children', 'parent' => '', 'taxons' => [], 'columns' => 4];
    }
}
