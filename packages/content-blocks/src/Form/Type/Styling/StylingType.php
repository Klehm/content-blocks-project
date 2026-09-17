<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\PaletteColorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
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

        if ($options['include_background_image']) {
            $this->addBackgroundImage($builder);
        }

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

        if ($options['include_text_align']) {
            $builder->add('textAlign', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'cb.styling.align.default',
                'expanded' => true,
                'label' => 'cb.styling.text_align',
                'choices' => [
                    'cb.styling.text_align.start' => 'start',
                    'cb.styling.text_align.center' => 'center',
                    'cb.styling.text_align.end' => 'end',
                    'cb.styling.text_align.justify' => 'justify',
                ],
                'block_prefix' => 'cb_horizontal_align',
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

    /** Section-only: the image, how it covers, and a veil for legible text. */
    private function addBackgroundImage(FormBuilderInterface $builder): void
    {
        $onlyWithImage = ['data-cb-condition' => 'backgroundImage'];
        $builder
            ->add('backgroundImage', ImageUploadType::class, [
                'required' => false,
                'label' => 'cb.styling.background_image',
            ])
            // The CSS default is the placeholder, so an untouched field
            // posts '' and stores nothing.
            ->add('backgroundSize', ChoiceType::class, [
                'required' => false,
                'label' => 'cb.styling.background_size',
                'placeholder' => 'cb.styling.background_size.cover',
                'choices' => [
                    'cb.styling.background_size.contain' => 'contain',
                ],
                'row_attr' => $onlyWithImage,
            ])
            ->add('backgroundPosition', ChoiceType::class, [
                'required' => false,
                'label' => 'cb.styling.background_position',
                'placeholder' => 'cb.styling.background_position.center',
                'choices' => [
                    'cb.styling.background_position.top' => 'top',
                    'cb.styling.background_position.bottom' => 'bottom',
                    'cb.styling.background_position.left' => 'left',
                    'cb.styling.background_position.right' => 'right',
                ],
                'row_attr' => $onlyWithImage,
            ])
            ->add('overlayColor', PaletteColorType::class, [
                'required' => false,
                'label' => 'cb.styling.overlay_color',
                'row_attr' => $onlyWithImage,
            ])
            ->add('overlayOpacity', RangeType::class, [
                'required' => false,
                'label' => 'cb.styling.overlay_opacity',
                'attr' => ['min' => 0, 'max' => 90, 'step' => 5],
                'row_attr' => $onlyWithImage,
            ]);

        // A range posts a string; the settings and the config hold an int.
        $builder->get('overlayOpacity')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $value): string => (string) (\is_numeric($value) ? (int) $value : 0),
            static fn (mixed $value): ?int => \is_numeric($value) && (int) $value > 0 ? min(100, (int) $value) : null,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'include_min_height' => false,
            'include_alignment' => false,
            'include_max_width' => false,
            'include_align_self' => false,
            'include_text_align' => false,
            'include_gap' => false,
            'include_background_image' => false,
            'translation_domain' => 'content_blocks',
            'label' => false,
        ]);
        $resolver->setAllowedTypes('include_min_height', 'bool');
        $resolver->setAllowedTypes('include_alignment', 'bool');
        $resolver->setAllowedTypes('include_max_width', 'bool');
        $resolver->setAllowedTypes('include_align_self', 'bool');
        $resolver->setAllowedTypes('include_gap', 'bool');
        $resolver->setAllowedTypes('include_text_align', 'bool');
        $resolver->setAllowedTypes('include_background_image', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cb_styling';
    }
}
