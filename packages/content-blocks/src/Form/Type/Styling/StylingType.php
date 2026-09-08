<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use ContentBlocks\Form\Type\PaletteColorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Every styling field, for sections and blocks alike — the irrelevant ones
 * gated by `include_*` options rather than by two near-identical types.
 *
 * @see docs/internals/forms.md#where-an-extension-lands-in-the-sidebar
 */
final class StylingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('padding', ResponsiveBoxSpacingType::class, [
                'label' => 'cb.styling.padding',
                'allow_negative' => false,
            ])
            ->add('margin', ResponsiveBoxSpacingType::class, [
                'label' => 'cb.styling.margin',
                'allow_negative' => true,
            ])
            // Its real empty state is what lets the styling defaults be
            // transparent instead of the old #ffffff hack.
            ->add('backgroundColor', PaletteColorType::class, [
                'required' => false,
                'label' => 'cb.styling.background_color',
            ]);

        if ($options['include_gap']) {
            // Section-only: the gap between columns, responsive (D/T/M).
            // Falls back to the framework default (1rem) when unset.
            $builder->add('gap', ResponsiveLengthType::class, [
                'label' => 'cb.styling.gap',
                'placeholder' => '16',
            ]);
        }

        if ($options['include_min_height']) {
            $builder->add('minHeight', LengthType::class, [
                'required' => false,
                'label' => 'cb.styling.min_height',
                'units' => ['px', 'vh'],
                'default_unit' => 'px',
                'placeholder' => '0',
            ]);
        }

        if ($options['include_max_width']) {
            $builder->add('maxWidth', LengthType::class, [
                'required' => false,
                'label' => 'cb.styling.max_width',
                'units' => ['px'],
                'default_unit' => 'px',
                'placeholder' => '1200',
            ]);
        }

        if ($options['include_alignment']) {
            $builder->add('verticalAlign', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'cb.styling.align.default',
                'expanded' => true,
                'label' => 'cb.styling.vertical_align',
                'choices' => [
                    'cb.styling.align.start' => 'start',
                    'cb.styling.align.center' => 'center',
                    'cb.styling.align.end' => 'end',
                ],
                // Custom block_prefix so the styling form theme can
                // render each radio as an icon button.
                'block_prefix' => 'cb_vertical_align',
            ]);
        }

        if ($options['include_align_self']) {
            // Only meaningful once maxWidth is set, so cb-block-styling-form
            // keeps the row hidden until then.
            $builder->add('alignSelf', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'cb.styling.align.default',
                'expanded' => true,
                'label' => 'cb.styling.horizontal_align',
                'choices' => [
                    'cb.styling.align.start' => 'start',
                    'cb.styling.align.center' => 'center',
                    'cb.styling.align.end' => 'end',
                ],
                'block_prefix' => 'cb_horizontal_align',
                'row_attr' => [
                    'data-cb-block-styling-form-target' => 'alignSelfRow',
                    'hidden' => 'hidden',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'include_min_height' => false,
            'include_alignment' => false,
            'include_max_width' => false,
            'include_align_self' => false,
            'include_gap' => false,
            'translation_domain' => 'content_blocks',
            'label' => false,
        ]);
        $resolver->setAllowedTypes('include_min_height', 'bool');
        $resolver->setAllowedTypes('include_alignment', 'bool');
        $resolver->setAllowedTypes('include_max_width', 'bool');
        $resolver->setAllowedTypes('include_align_self', 'bool');
        $resolver->setAllowedTypes('include_gap', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cb_styling';
    }
}
