<?php

declare(strict_types=1);

namespace App\ContentBlocks\Form;

use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Reference example — host fields in the section sidebar, laid out with the
 * core's sidebar options rather than templates.
 *
 * Extending StylingType puts the fields in the Style tab; `cb_panel` gathers
 * them into their own collapsible "Ornaments" panel; `cb_icons` draws each
 * choice as an icon button — a 2×2 grid of corners (radios) and a row of sides
 * (checkboxes, since `multiple` is on). The icon names come from the core set;
 * a host adds its own through a `UiIconProviderInterface` service.
 *
 * The values land in the section's settings under `styling`, like any styling
 * field; rendering them is a SectionDecoratorInterface's job, left out here.
 */
final class SectionOrnamentExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [StylingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // StylingType serves blocks too: only the section form gets these.
        if (!$options['include_background_image']) {
            return;
        }

        $builder
            ->add('ornamentCorner', ChoiceType::class, [
                'label' => 'app.ornament.corner',
                'required' => false,
                'placeholder' => 'app.ornament.corner.none',
                'choices' => [
                    'app.ornament.corner.tl' => 'tl',
                    'app.ornament.corner.tr' => 'tr',
                    'app.ornament.corner.bl' => 'bl',
                    'app.ornament.corner.br' => 'br',
                ],
                // The placeholder ('') is not given an icon: it trails the
                // grid as a text button.
                'cb_icons' => ['tl' => 'corner-tl', 'tr' => 'corner-tr', 'bl' => 'corner-bl', 'br' => 'corner-br'],
                'cb_icon_layout' => 'grid',
                'cb_icon_columns' => 2,
            ] + $this->shared())
            ->add('ornamentSides', ChoiceType::class, [
                'label' => 'app.ornament.sides',
                'required' => false,
                'multiple' => true,
                'choices' => [
                    'app.ornament.side.top' => 'top',
                    'app.ornament.side.right' => 'right',
                    'app.ornament.side.bottom' => 'bottom',
                    'app.ornament.side.left' => 'left',
                ],
                'cb_icons' => ['top' => 'side-top', 'right' => 'side-right', 'bottom' => 'side-bottom', 'left' => 'side-left'],
                'help' => 'app.ornament.sides_help',
                'cb_help_tooltip' => 'app.ornament.sides_tooltip',
            ] + $this->shared());
    }

    /**
     * The app's own labels: this domain for the field, a translatable for the
     * panel (the package reads a plain string in its own domain).
     *
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        return [
            'translation_domain' => 'messages',
            'cb_panel' => new TranslatableMessage('app.ornament.panel', [], 'messages'),
        ];
    }
}
