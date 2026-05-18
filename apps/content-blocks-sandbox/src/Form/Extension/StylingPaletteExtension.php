<?php

declare(strict_types=1);

namespace App\Form\Extension;

use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Sandbox example: overrides the Styling sub-form's `backgroundColor`
 * widget with a brand-palette ChoiceType so editors pick from a curated
 * set of colors instead of getting the raw HTML5 color picker.
 *
 * Pattern reference: extensions targeting StylingType (rather than
 * SectionSettingsType) inject into the sidebar's "Styling" tab — they
 * can either add new fields or, as shown here, replace an existing one
 * by calling `$builder->add()` with the same name. Symfony's form
 * builder treats the second `add()` as an override.
 *
 * To inject extra Styling fields (not override), keep the field name
 * unique and add it the usual way.
 */
final class StylingPaletteExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [StylingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('backgroundColor', ChoiceType::class, [
            'required' => false,
            'placeholder' => 'cb.styling.align.default',
            'label' => 'cb.styling.background_color',
            'choices' => [
                'Brand / Primary' => '#0a84ff',
                'Brand / Accent' => '#ff375f',
                'Neutral / Light' => '#f5f6f8',
                'Neutral / Dark' => '#1f2330',
            ],
            'translation_domain' => 'content_blocks',
        ]);
    }
}
