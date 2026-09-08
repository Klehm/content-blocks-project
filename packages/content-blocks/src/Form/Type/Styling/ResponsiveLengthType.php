<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * {@see ResponsiveBoxSpacingType} with one integer per viewport instead of a
 * four-side box. Used for the section gap.
 *
 * @see docs/internals/forms.md#the-responsive-styling-sub-types
 */
final class ResponsiveLengthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            $builder->add($viewport, IntegerType::class, [
                'required' => false,
                'attr' => [
                    'min' => $options['min'],
                    'placeholder' => $options['placeholder'],
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'content_blocks',
            'min' => 0,
            'placeholder' => '',
        ]);
        $resolver->setAllowedTypes('min', 'int');
        $resolver->setAllowedTypes('placeholder', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'cb_responsive_length';
    }
}
