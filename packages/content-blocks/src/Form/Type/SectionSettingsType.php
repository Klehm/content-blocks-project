<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Form\Type\Styling\StylingType;
use ContentBlocks\Section\SectionStyleRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Built-in form for the section settings sidebar, extended the standard
 * Symfony way. Extra fields land in `draft_settings` unchanged.
 *
 * @see docs/internals/forms.md#where-an-extension-lands-in-the-sidebar
 */
final class SectionSettingsType extends AbstractType
{
    public function __construct(
        private readonly SectionStyleRegistry $styleRegistry,
        private readonly int $defaultMaxWidth = 1320,
        private readonly string $defaultWidthMode = 'full',
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('classes', TextType::class, [
                'required' => false,
                'label' => 'cb.section.settings.classes',
                'help' => 'cb.section.settings.classes_help',
            ])
            ->add('widthMode', ChoiceType::class, [
                'required' => true,
                'expanded' => true,
                'choices' => [
                    'cb.section.settings.width.full' => 'full',
                    'cb.section.settings.width.centered' => 'centered',
                ],
                'label' => 'cb.section.settings.width',
                'data' => $options['data']['widthMode'] ?? $this->defaultWidthMode,
            ])
            ->add('maxWidth', IntegerType::class, [
                'required' => false,
                'label' => 'cb.section.settings.max_width',
                // Only seen once the user clears the field, but kept in sync
                // with the configured default so the hint never lies.
                'attr' => ['placeholder' => (string) $this->defaultMaxWidth],
            ]);

        // A CSV of percentages summing to 100 ("40,60"), kept canonical by
        // cb-section-settings-form. The visible inputs live in the template.
        if ($options['column_count'] >= 2) {
            $builder->add('columnWidths', HiddenType::class, [
                'required' => false,
                'label' => 'cb.section.settings.column_widths',
            ]);
        }

        $choices = $this->styleRegistry->getChoices();
        if (!empty($choices)) {
            $builder->add('styleName', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'cb.section.settings.style.none',
                'choices' => $choices,
                'label' => 'cb.section.settings.style',
            ]);
        }

        // Off drops the styling subtree on save, so switching presets never
        // fights stale field values. See forms.md.
        $builder->add('stylingCustom', CheckboxType::class, [
            'required' => false,
            'label' => 'cb.section.settings.styling_custom',
            'help' => 'cb.section.settings.styling_custom_help',
        ]);

        // Extensions targeting this type land in "General"; to reach
        // "Styling", extend StylingType instead.
        $builder->add('styling', StylingType::class, [
            'include_min_height' => true,
            'include_alignment' => true,
            'include_gap' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'content_blocks',
            // Drives whether the column-widths control is offered at all.
            'column_count' => 1,
        ]);
        $resolver->setAllowedTypes('column_count', 'int');
    }
}
