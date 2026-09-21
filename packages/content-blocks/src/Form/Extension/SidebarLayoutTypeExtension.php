<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Where a field sits in the builder sidebars: its tab (`cb_group`), its
 * collapsible panel (`cb_panel`), and a tooltip after its help.
 *
 * @see docs/guide/sidebar-fields.md
 */
final class SidebarLayoutTypeExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'cb_group' => null,
            'cb_panel' => null,
            'cb_help_tooltip' => null,
            'cb_panels_exclusive' => true,
        ]);
        $label = ['null', 'string', TranslatableInterface::class];
        $resolver->setAllowedTypes('cb_group', $label);
        $resolver->setAllowedTypes('cb_panel', $label);
        $resolver->setAllowedTypes('cb_help_tooltip', $label);
        $resolver->setAllowedTypes('cb_panels_exclusive', 'bool');
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // `data-cb-group` is the attribute this option replaced; still read.
        $group = $options['cb_group'] ?? $options['attr']['data-cb-group'] ?? null;
        $view->vars['cb_group'] = $group === '' ? null : $group;
        $view->vars['cb_panel'] = $options['cb_panel'] === '' ? null : $options['cb_panel'];
        $view->vars['cb_help_tooltip'] = $options['cb_help_tooltip'];
        // Off anywhere up the tree is off below: one switch on the root.
        $view->vars['cb_panels_exclusive'] = $options['cb_panels_exclusive']
            && ($view->parent?->vars['cb_panels_exclusive'] ?? true);
    }
}
