<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Wraps a BlockTypeInterface's own buildForm() in a real Symfony form, so it
 * gets validation and theming. One form type serves every block.
 *
 * @see docs/internals/forms.md#the-block-form-is-the-whitelist
 */
final class BlockFormType extends AbstractType
{
    public function __construct(
        private readonly BlockFormExtensionCollection $extensions,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $blockType = $options['block_type'];
        \assert($blockType instanceof BlockTypeInterface);

        $blockType->buildForm($builder, $options['block_data']);

        // After the block's own fields, so they can reference or override
        // them; before the styling tab, which stays last.
        $this->extensions->applyTo($builder, $options['block_data'], $blockType::getType());

        // Lands under the `styling` key of Block.data, which is why a block
        // type's getDefaultData() never declares it.
        $builder->add('styling', StylingType::class, [
            'include_max_width' => true,
            'include_align_self' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'block_data' => [],
            // Safe only because this form is submitted through a Live
            // Component. See forms.md, "disables form-level CSRF".
            'csrf_protection' => false,
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
