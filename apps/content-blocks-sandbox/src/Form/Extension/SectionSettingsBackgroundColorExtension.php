<?php

declare(strict_types=1);

namespace App\Form\Extension;

use ContentBlocks\Form\Type\SectionSettingsType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;

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
        $builder->add('backgroundColor', ColorType::class, [
            'required' => false,
            'label' => 'Background color',
        ]);
    }
}
