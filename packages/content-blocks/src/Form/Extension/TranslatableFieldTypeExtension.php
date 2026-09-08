<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Makes the `cb_translatable` option legal on every form type. A declaration
 * carrying no behaviour, not to be confused with the per-block seam.
 *
 * @see docs/internals/forms.md#the-cb_translatable-option-is-a-declaration
 */
final class TranslatableFieldTypeExtension extends AbstractTypeExtension
{
    public const OPTION = 'cb_translatable';

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Defined rather than defaulted, so a reader can tell "not tagged"
        // from "tagged false" without every form carrying the key.
        $resolver->setDefined(self::OPTION);
        $resolver->setAllowedTypes(self::OPTION, 'bool');
    }
}
