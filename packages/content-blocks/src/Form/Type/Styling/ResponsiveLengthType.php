<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Per-viewport single length (px): desktop / tablet / mobile values under
 * fixed keys (`d`, `t`, `m`). Like ResponsiveBoxSpacingType but a single
 * integer per viewport instead of a 4-side box — used for the section gap.
 *
 * The viewport switcher (cb-viewport-tabs) shows one at a time in the
 * sidebar; the form always submits all three. Unset tablet/mobile inherit
 * from the next-wider value via CSS var cascading at render time.
 */
final class ResponsiveLengthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['d', 't', 'm'] as $viewport) {
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
