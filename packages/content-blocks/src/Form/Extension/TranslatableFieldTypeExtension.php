<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Declares the `cb_translatable` form option on every form type.
 *
 * Not to be confused with {@see BlockFormExtensionInterface}, this package's own
 * per-block-type API: this is a stock Symfony `FormTypeExtension`, and its only
 * job is to make the option legal so a block can tag a field without Symfony
 * rejecting an undefined option:
 *
 *     $builder->add('title', TextType::class, [
 *         'label' => 'cb_kit.block.card.field.title',
 *         'cb_translatable' => true,
 *     ]);
 *
 * The option carries no behaviour on its own — the core never reads it at
 * render or save time, and with no translation package installed tagging a
 * field changes nothing. It is a **declaration**: "this field holds text an
 * editor would want in another language". {@see \ContentBlocks\Translation\TranslatableFieldsInterface}
 * reads the tags back.
 *
 * The convention ships at 1.0 rather than with the translation package so that
 * host blocks and kit blocks tag identically from day one — a package arriving
 * later would find a field set nobody had annotated.
 *
 * Tag fields whose value legitimately **differs between languages**: prose
 * (headings, body copy, labels, alt text, captions) and link targets, since a
 * localized site routinely points at `/fr/contact` rather than `/contact`.
 * Leave out enums, colors, sizes and IDs — they are the same in every language,
 * and a locale payload carrying an untagged field is ignored at render.
 *
 * Uploaded assets (an image `src`) are deliberately left untagged by the kit:
 * swapping a visual per locale is an asset-management decision, not a text
 * translation. Tagging one later is purely additive, so a host that wants it
 * can add the tag through a block form extension.
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
        // Defined rather than defaulted: an untagged field then leaves no entry
        // in the resolved options at all, so the reader can tell "not tagged"
        // from "tagged false" without every form in the app carrying the key.
        $resolver->setDefined(self::OPTION);
        $resolver->setAllowedTypes(self::OPTION, 'bool');
    }
}
