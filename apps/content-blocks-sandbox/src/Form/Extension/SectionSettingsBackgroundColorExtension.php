<?php

declare(strict_types=1);

namespace App\Form\Extension;

use ContentBlocks\Form\Type\SectionSettingsType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Sandbox example: adds a `backgroundColor` color picker to the package's
 * SectionSettingsType form. Whatever the user picks lands in the
 * section's draft_settings JSON unchanged; the matching
 * BackgroundColorSectionDecorator picks it up at render time.
 *
 * Symfony auto-discovers FormTypeExtension implementations when service
 * autoconfigure is on — no tag needed.
 */
final class SectionSettingsBackgroundColorExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [SectionSettingsType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // TextType (rather than ColorType) so the field can stay empty on
        // mount: HTML5 color inputs have no "no value" state — they always
        // submit something (#000000 by default), which would force a black
        // background even when the user hasn't picked anything.
        $builder->add('backgroundColor', TextType::class, [
            'required' => false,
            'label' => 'Background color',
            'attr' => [
                'placeholder' => '#ffeecc',
                'autocomplete' => 'off',
            ],
            'help' => 'CSS color (hex, rgb(), or named — e.g. #ffeecc).',
            'constraints' => [
                new Regex(
                    pattern: '/^(#[0-9a-fA-F]{3,8}|rgb\([^)]+\)|rgba\([^)]+\)|hsl\([^)]+\)|hsla\([^)]+\)|[a-z]+)$/',
                    message: 'Enter a CSS color value (hex, rgb(), rgba(), hsl(), hsla(), or a named color).',
                ),
            ],
        ]);
    }
}
