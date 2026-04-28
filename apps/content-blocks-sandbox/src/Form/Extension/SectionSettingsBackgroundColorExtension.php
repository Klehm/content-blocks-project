<?php

declare(strict_types=1);

namespace App\Form\Extension;

use ContentBlocks\Form\Type\SectionSettingsType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Sandbox example: adds a `backgroundColor` ColorType picker to the
 * package's SectionSettingsType form.
 *
 * Whatever the user picks lands in the section's draft_settings JSON
 * unchanged; {@see App\Section\BackgroundColorSectionDecorator} reads
 * it at render time. The browser-default #000000 problem with HTML5
 * color inputs is sidestepped by the matching
 * {@see App\Section\SandboxSectionSettingsDefaults} provider — it sets
 * a `#ffffff` default so the picker opens on white. The framework
 * strips default-equal entries before decorators run, so leaving the
 * picker on its default produces no inline style at all.
 *
 * Symfony auto-discovers FormTypeExtension implementations when the
 * host's service is autoconfigured — no tag needed.
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
