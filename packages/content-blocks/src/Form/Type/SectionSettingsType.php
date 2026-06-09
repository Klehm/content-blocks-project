<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Form\Type\Styling\StylingType;
use ContentBlocks\Section\SectionStyleRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Built-in form for the section settings sidebar.
 *
 * Devs extend it the standard Symfony way — register a FormTypeExtension
 * for SectionSettingsType and add fields:
 *
 *     final class MySettingsExtension extends AbstractTypeExtension {
 *         public static function getExtendedTypes(): iterable {
 *             return [SectionSettingsType::class];
 *         }
 *         public function buildForm(FormBuilderInterface $builder, array $options): void {
 *             $builder->add('backgroundColor', ColorType::class, ['required' => false]);
 *         }
 *     }
 *
 * The extra field's value lands in the section's draft_settings JSON
 * unchanged. To act on it at render time, register a
 * SectionDecoratorInterface that reads $settings['backgroundColor'] and
 * returns inline styles or extra classes.
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
                // The form is normally pre-filled by CoreSectionDefaults so
                // this placeholder is only seen when the user clears the
                // field; we still keep it in sync with the configured
                // default so the hint never lies.
                'attr' => ['placeholder' => (string) $this->defaultMaxWidth],
            ]);

        $choices = $this->styleRegistry->getChoices();
        if (!empty($choices)) {
            $builder->add('styleName', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'cb.section.settings.style.none',
                'choices' => $choices,
                'label' => 'cb.section.settings.style',
            ]);
        }

        // Styling sub-form: rendered under the "Styling" sidebar tab.
        // Extensions targeting SectionSettingsType land in "General"; to
        // inject fields into "Styling" extend StylingType instead.
        $builder->add('styling', StylingType::class, [
            'include_min_height' => true,
            'include_alignment' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'content_blocks',
        ]);
    }
}
