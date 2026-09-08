<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Per-viewport {@see BoxSpacingType} under fixed `desktop` / `tablet` /
 * `mobile` keys. An empty viewport is not a bug — it inherits at render.
 *
 * @see docs/internals/forms.md#the-responsive-styling-sub-types
 */
final class ResponsiveBoxSpacingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
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
