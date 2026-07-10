<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/** One row (a collection of cells) of a {@see \ContentBlocks\Kit\Block\TableBlock}. */
final class TableRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('cells', LiveCollectionType::class, [
            'label' => 'cb_kit.block.table.field.cells',
            'translation_domain' => 'content_blocks_kit',
            'entry_type' => TableCellType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'button_add_options' => [
                'label' => 'cb_kit.block.table.action.add_cell',
                'translation_domain' => 'content_blocks_kit',
                'attr' => ['class' => 'cb-form-btn--success'],
            ],
            'button_delete_options' => [
                'label' => 'cb_kit.block.action.remove_item',
                'translation_domain' => 'content_blocks_kit',
                'attr' => ['class' => 'cb-form-btn--danger'],
            ],
            'constraints' => [new Assert\Count(min: 1, max: 12)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
