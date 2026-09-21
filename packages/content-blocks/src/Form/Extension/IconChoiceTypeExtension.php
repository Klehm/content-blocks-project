<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * `cb_icons` on a ChoiceType: each choice drawn as an icon button, radios or
 * checkboxes underneath. Icons are names from the UI icon registry.
 *
 * @see docs/guide/sidebar-fields.md#icon-choices
 */
final class IconChoiceTypeExtension extends AbstractTypeExtension
{
    public const BLOCK_PREFIX = 'cb_icon_choice';

    public static function getExtendedTypes(): iterable
    {
        return [ChoiceType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'cb_icons' => null,
            'cb_icon_layout' => 'row',
            'cb_icon_columns' => null,
            'cb_icon_labels' => false,
        ]);
        $resolver->setAllowedTypes('cb_icons', ['null', 'array']);
        $resolver->setAllowedValues('cb_icon_layout', ['row', 'grid']);
        $resolver->setAllowedTypes('cb_icon_columns', ['null', 'int']);
        $resolver->setAllowedTypes('cb_icon_labels', 'bool');
        // A select has no room for an icon, so asking for icons expands.
        $resolver->addNormalizer(
            'expanded',
            static fn (Options $options, mixed $expanded): mixed => $options['cb_icons'] !== null ? true : $expanded,
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        if ($options['cb_icons'] === null) {
            return;
        }

        self::decorate(
            $view,
            $options['cb_icons'],
            $options['cb_icon_layout'],
            $options['cb_icon_columns'],
            $options['cb_icon_labels'],
        );
    }

    /**
     * The same, from a parent type's finishView(): how the core's own fields
     * get their icons without a form factory needing this extension.
     *
     * @param array<int|string, string|null> $icons choice value => icon name
     */
    public static function decorate(
        FormView $view,
        array $icons,
        string $layout = 'row',
        ?int $columns = null,
        bool $labels = false,
    ): void {
        // Ahead of the field's own prefix, so a theme block aimed at that one
        // field still wins.
        $prefixes = $view->vars['block_prefixes'];
        if (!\in_array(self::BLOCK_PREFIX, $prefixes, true)) {
            array_splice($prefixes, -1, 0, [self::BLOCK_PREFIX]);
            $view->vars['block_prefixes'] = $prefixes;
        }

        $names = [];
        foreach ($icons as $value => $icon) {
            $names[(string) $value] = \is_string($icon) && $icon !== '' ? $icon : null;
        }
        $view->vars['cb_icons'] = $names;
        $view->vars['cb_icon_layout'] = $layout;
        $view->vars['cb_icon_columns'] = $columns;
        $view->vars['cb_icon_labels'] = $labels;
    }
}
