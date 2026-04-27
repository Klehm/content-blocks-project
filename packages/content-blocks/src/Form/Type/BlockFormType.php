<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\BlockType\BlockTypeInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Dynamic FormType that delegates field building to a BlockTypeInterface.
 *
 * Each block type defines its own fields via buildForm(). This FormType
 * wraps that call so we get a real Symfony Form with validation, theming, etc.
 */
final class BlockFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $blockType = $options['block_type'];
        \assert($blockType instanceof BlockTypeInterface);

        $blockType->buildForm($builder, $options['block_data']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'block_data' => [],
        ]);

        $resolver->setRequired('block_type');
        $resolver->setAllowedTypes('block_type', BlockTypeInterface::class);
        $resolver->setAllowedTypes('block_data', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'content_block';
    }
}
