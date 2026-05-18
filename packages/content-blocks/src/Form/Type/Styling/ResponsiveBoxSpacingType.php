<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Per-viewport BoxSpacingType: holds desktop / tablet / mobile values
 * under fixed keys (`d`, `t`, `m`). The viewport switcher (cb-viewport-tabs
 * Stimulus controller) shows one set at a time in the sidebar — the form
 * always submits all three.
 *
 * Tablet / mobile inherit from the next-wider unset value via CSS var
 * cascading at render time (PR 2), so empty viewports are not a bug.
 */
final class ResponsiveBoxSpacingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['d', 't', 'm'] as $viewport) {
            $builder->add($viewport, BoxSpacingType::class, [
                'allow_negative' => $options['allow_negative'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'allow_negative' => false,
            'translation_domain' => 'content_blocks',
        ]);
        $resolver->setAllowedTypes('allow_negative', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cb_responsive_box_spacing';
    }
}
