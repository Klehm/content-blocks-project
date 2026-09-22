<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * Which entries of a live collection open unfolded (`cb_open_entries`):
 * all, the first, the last or none. The rest start folded to their header.
 *
 * @see docs/guide/sidebar-fields.md
 */
final class CollectionFoldTypeExtension extends AbstractTypeExtension
{
    public const OPEN = ['all', 'first', 'last', 'none'];

    public static function getExtendedTypes(): iterable
    {
        return [LiveCollectionType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('cb_open_entries', 'all');
        $resolver->setAllowedValues('cb_open_entries', self::OPEN);
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $entries = array_values($view->children);
        $last = \count($entries) - 1;
        foreach ($entries as $index => $entry) {
            $entry->vars['cb_folded'] = match ($options['cb_open_entries']) {
                'first' => $index !== 0,
                'last' => $index !== $last,
                'none' => true,
                default => false,
            };
        }
    }
}
