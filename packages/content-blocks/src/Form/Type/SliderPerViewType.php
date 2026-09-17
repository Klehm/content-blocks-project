<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Section\SectionDisplay;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Slides a slider shows at once, per viewport. An empty viewport inherits the
 * one above.
 *
 * @see docs/internals/rendering.md#the-slider
 */
final class SliderPerViewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (SectionDisplay::VIEWPORTS as $viewport) {
            $builder->add($viewport, IntegerType::class, [
                'required' => false,
                'label' => 'cb.styling.viewport.' . $viewport,
                'attr' => [
                    'min' => 1,
                    'max' => SectionDisplay::MAX_PER_VIEW,
                    'placeholder' => $viewport === 'desktop' ? '1' : '',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'required' => false,
            'translation_domain' => 'content_blocks',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'cb_slider_per_view';
    }
}
