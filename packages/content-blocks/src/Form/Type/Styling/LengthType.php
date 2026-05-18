<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A value paired with a unit (e.g. 400 px, 100 vh). Used by minHeight,
 * maxWidth, and any future scalar length input.
 *
 * Data shape: ['value' => int|null, 'unit' => string].
 */
final class LengthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('value', IntegerType::class, [
                'required' => false,
                'attr' => ['min' => $options['min'], 'placeholder' => $options['placeholder']],
            ])
            ->add('unit', ChoiceType::class, [
                'required' => true,
                'choices' => array_combine($options['units'], $options['units']),
                'data' => $options['default_unit'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'units' => ['px'],
            'default_unit' => 'px',
            'min' => 0,
            'placeholder' => null,
            'translation_domain' => 'content_blocks',
        ]);
        $resolver->setAllowedTypes('units', 'string[]');
        $resolver->setAllowedTypes('default_unit', 'string');
        $resolver->setAllowedTypes('min', 'int');
        $resolver->setAllowedTypes('placeholder', ['null', 'string']);
    }

    public function getBlockPrefix(): string
    {
        return 'cb_length';
    }
}
