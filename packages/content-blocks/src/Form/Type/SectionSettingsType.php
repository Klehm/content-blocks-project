<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Form\Type\Styling\StylingType;
use ContentBlocks\Section\SectionDisplay;
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
    /** `||` separates alternatives in cb-condition, `;` joins clauses. */
    private const ANY_SLIDER = 'display:slider||displayTablet:slider||displayMobile:slider';
    private const ACCORDION_OWN = 'display:accordion'
        . '||display:grid|slider;displayTablet:accordion'
        . '||display:grid|slider;displayMobile:accordion';
    private const MOBILE_GRID = 'displayMobile:grid'
        . '||displayMobile:inherit;displayTablet:grid'
        . '||displayMobile:inherit;displayTablet:inherit;display:grid';

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
            ->add('display', ChoiceType::class, [
                'required' => true,
                'expanded' => true,
                'choices' => [
                    'cb.section.settings.display.grid' => SectionDisplay::GRID,
                    'cb.section.settings.display.slider' => SectionDisplay::SLIDER,
                    'cb.section.settings.display.tabs' => SectionDisplay::TABS,
                    'cb.section.settings.display.accordion' => SectionDisplay::ACCORDION,
                ],
                'label' => 'cb.section.settings.display',
                'data' => SectionDisplay::fromSettings($options['data'] ?? []),
            ])
            // Tabs never appear below: from tabs, "inherit" is tabs.
            ->add(SectionDisplay::SETTING_TABLET, ChoiceType::class, self::belowOptions(
                $options['data'][SectionDisplay::SETTING_TABLET] ?? null,
            ))
            ->add(SectionDisplay::SETTING_MOBILE, ChoiceType::class, self::belowOptions(
                $options['data'][SectionDisplay::SETTING_MOBILE] ?? null,
            ))
            ->add(SectionDisplay::ACCORDION_SINGLE, CheckboxType::class, [
                'required' => false,
                'label' => 'cb.section.settings.accordion_single',
                'row_attr' => ['data-cb-condition' => self::ACCORDION_OWN],
            ])
            ->add(SectionDisplay::ACCORDION_COLLAPSED, CheckboxType::class, [
                'required' => false,
                'label' => 'cb.section.settings.accordion_collapsed',
                'row_attr' => ['data-cb-condition' => self::ACCORDION_OWN],
            ])
            ->add(SectionDisplay::SLIDER_PER_VIEW, SliderPerViewType::class, [
                'label' => 'cb.section.settings.slider_per_view',
                'row_attr' => ['data-cb-condition' => self::ANY_SLIDER],
            ])
            ->add(SectionDisplay::SLIDER_CONTROLS, ChoiceType::class, [
                'required' => true,
                'choices' => [
                    'cb.section.settings.slider_controls.both' => 'both',
                    'cb.section.settings.slider_controls.arrows' => 'arrows',
                    'cb.section.settings.slider_controls.dots' => 'dots',
                    'cb.section.settings.slider_controls.none' => 'none',
                ],
                'label' => 'cb.section.settings.slider_controls',
                'data' => SectionDisplay::sliderOptions($options['data'] ?? [])['controls'],
                'row_attr' => ['data-cb-condition' => self::ANY_SLIDER],
            ])
            ->add(SectionDisplay::SLIDER_AUTOPLAY, IntegerType::class, [
                'required' => false,
                'label' => 'cb.section.settings.slider_autoplay',
                'help' => 'cb.section.settings.slider_autoplay_help',
                'attr' => ['min' => 0, 'max' => SectionDisplay::MAX_AUTOPLAY, 'placeholder' => '0'],
                'row_attr' => ['data-cb-condition' => self::ANY_SLIDER],
            ])
            ->add(SectionDisplay::SLIDER_LOOP, CheckboxType::class, [
                'required' => false,
                'label' => 'cb.section.settings.slider_loop',
                'row_attr' => ['data-cb-condition' => self::ANY_SLIDER],
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

        if ($options['column_count'] >= 2) {
            $builder->add('reverseOnMobile', CheckboxType::class, [
                'required' => false,
                'label' => 'cb.section.settings.reverse_on_mobile',
                'help' => 'cb.section.settings.reverse_on_mobile_help',
                'row_attr' => ['data-cb-condition' => self::MOBILE_GRID],
            ]);
        }

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
            'include_background_image' => true,
        ]);
    }

    /**
     * `inherit` is dropped on save by {@see SectionDisplay::normalize()}.
     *
     * @return array<string, mixed>
     */
    private static function belowOptions(mixed $current): array
    {
        return [
            'required' => true,
            'expanded' => true,
            'data' => \in_array($current, SectionDisplay::ALL, true) ? $current : SectionDisplay::INHERIT,
            'choices' => [
                'cb.section.settings.display.inherit' => SectionDisplay::INHERIT,
                'cb.section.settings.display.grid' => SectionDisplay::GRID,
                'cb.section.settings.display.slider' => SectionDisplay::SLIDER,
                'cb.section.settings.display.accordion' => SectionDisplay::ACCORDION,
            ],
            'label' => false,
        ];
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
