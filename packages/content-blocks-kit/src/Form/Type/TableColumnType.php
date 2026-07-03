<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** One column header (label + alignment) of a {@see \ContentBlocks\Kit\Block\TableBlock}. */
final class TableColumnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'cb_kit.block.table.field.column_label',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'cb_kit.block.field.align',
                'translation_domain' => 'content_blocks_kit',
                'choices' => [
                    'cb_kit.block.align.left' => 'left',
                    'cb_kit.block.align.center' => 'center',
                    'cb_kit.block.align.right' => 'right',
                ],
                'constraints' => [new Assert\Choice(choices: ['left', 'center', 'right'])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
