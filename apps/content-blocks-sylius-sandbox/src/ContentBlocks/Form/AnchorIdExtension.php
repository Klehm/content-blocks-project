<?php

declare(strict_types=1);

namespace App\ContentBlocks\Form;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Reference example — **global** block form extension.
 *
 * `#[AsBlockFormExtension]` with no arguments targets every block type, so the
 * same cross-cutting field (here: an HTML anchor id, to deep-link a block) is
 * added to every block's edit form. `$blockType` tells the extension which
 * block is being built, if it needs to branch.
 *
 * `priority: -100` makes it run *after* every other extension, so the field
 * lands at the end of the form.
 *
 * Rendering is handled by {@see \App\ContentBlocks\Block\AnchorIdBlockDecorator},
 * which turns the stored value into the `id` attribute of the block wrapper —
 * the pair is the idiomatic way to add a global, render-affecting field.
 */
#[AsBlockFormExtension(priority: -100)]
final class AnchorIdExtension implements BlockFormExtensionInterface
{
    /** Valid HTML id: starts with a letter, then letters/digits/-/_. */
    public const PATTERN = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $builder->add('anchorId', TextType::class, [
            'label' => 'Anchor id',
            'required' => false,
            'data' => $data['anchorId'] ?? '',
            // Regex skips null/'' — an empty value simply means "no anchor".
            'constraints' => [
                new Assert\Regex(self::PATTERN, message: 'Use a letter, then letters, digits, - or _.'),
                new Assert\Length(max: 64),
            ],
            'attr' => [
                'data-cb-group' => 'SEO',
                'placeholder' => 'pricing',
            ],
            'help' => sprintf(
                'Added to every block by App\ContentBlocks\Form\AnchorIdExtension (this one: "%s"). Renders as id="…" on the block wrapper.',
                $blockType,
            ),
        ]);
    }
}
