<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One text line inside a {@see \ContentBlocks\Kit\Block\ListBlock}.
 */
final class ListItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('text', TextType::class, [
            'cb_translatable' => true,
            'label' => 'cb_kit.block.list.field.item_text',
            'translation_domain' => 'content_blocks_kit',
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 500)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
