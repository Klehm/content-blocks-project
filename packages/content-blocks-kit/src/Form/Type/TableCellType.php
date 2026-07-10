<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** One cell of a {@see \ContentBlocks\Kit\Block\TableBlock} row. */
final class TableCellType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextareaType::class, [
            'label' => 'cb_kit.block.table.field.cell_content',
            'translation_domain' => 'content_blocks_kit',
            'required' => false,
            'attr' => ['rows' => 2],
            'constraints' => [new Assert\Length(max: 1000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
