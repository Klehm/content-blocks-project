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
                'cb_translatable' => true,
                'label' => 'cb_kit.block.table.field.column_label',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'cb_kit.block.field.align',
                'translation_domain' => 'content_blocks_kit',
                'choices' => [
                    'cb_kit.block.align.left' => 'start',
                    'cb_kit.block.align.center' => 'center',
                    'cb_kit.block.align.right' => 'end',
                ],
                'constraints' => [new Assert\Choice(choices: ['start', 'center', 'end'])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
