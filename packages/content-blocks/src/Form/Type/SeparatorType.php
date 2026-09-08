<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Non-mapped pseudo-field rendering an `<hr>` in the sidebar, so a block can
 * group its fields. Themed by `cb_form_theme.html.twig`, always loaded.
 */
final class SeparatorType extends AbstractType
{
    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cb_separator';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'required' => false,
            'label' => false,
        ]);
    }
}
