<?php

declare(strict_types=1);

namespace App\ContentBlocks\Form;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Reference example — **targeted** block form extension.
 *
 * Adds a `rel` field to the kit's `button` block only, without subclassing it
 * and without touching the kit. `#[AsBlockFormExtension('button')]` is the
 * whole wiring: the attribute is autoconfigured, so the standard `App\`
 * service registration is enough.
 *
 * The value round-trips into `Block.data` like any of the block's own fields
 * (block data is not pruned), and is rendered by this app's override of the
 * kit template — see templates/bundles/ContentBlocksKitBundle/block/button/view.html.twig.
 */
#[AsBlockFormExtension('button')]
final class ButtonRelExtension implements BlockFormExtensionInterface
{
    /** Values offered in the picker; also the render-side allow-list. */
    public const REL_VALUES = ['nofollow', 'sponsored', 'ugc'];

    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $builder->add('rel', ChoiceType::class, [
            'label' => 'Link relation (rel)',
            'required' => false,
            'placeholder' => 'None',
            'choices' => array_combine(self::REL_VALUES, self::REL_VALUES),
            'data' => $data['rel'] ?? '',
            // The form is the whitelist: an unexpected POST value is rejected
            // here and nothing is written to the draft.
            'constraints' => [new Assert\Choice(choices: [null, '', ...self::REL_VALUES])],
            // Puts the field in its own "SEO" tab of the block sidebar, next to
            // the global anchor id below (see AnchorIdExtension).
            'attr' => ['data-cb-group' => 'SEO'],
            'help' => 'Added by App\ContentBlocks\Form\ButtonRelExtension (targets the "button" block).',
        ]);
    }
}
