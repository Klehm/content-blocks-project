<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Non-mapped pseudo-field that renders an `<hr>` in the builder sidebar, so a
 * block can visually group its fields. Carries no data and is excluded from the
 * submitted/persisted block data.
 *
 * The `cb_separator` block prefix is picked up by the generic
 * `@ContentBlocks/form/cb_form_theme.html.twig` theme (always loaded by the
 * Block component), so no extra getFormTheme() wiring is needed on the block.
 *
 * Usage: `->add('sep_whatever', SeparatorType::class)` between two fields.
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
