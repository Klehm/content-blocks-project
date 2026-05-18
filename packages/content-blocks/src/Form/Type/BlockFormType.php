<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\Form\Type\Styling\StylingType;
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

        // Styling sub-form: rendered under a "Styling" tab in the block
        // sidebar (mirror of SectionSettingsType). Data lands under the
        // `styling` key of Block.data — block types' getDefaultData()
        // doesn't need to declare it.
        $builder->add('styling', StylingType::class, [
            'include_max_width' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'block_data' => [],
            // Form-level CSRF disabled: this form is only ever submitted through
            // BlockComponent (Live Component), whose own CSRF defense applies —
            // the action endpoint requires the Accept: application/vnd.live-component+html
            // header, which a cross-origin <form> cannot send (CORS blocks it),
            // and LiveProp values are signed with kernel.secret (HMAC checksum).
            // Authorization is enforced by canEdit() in BlockComponent::save().
            // Reason for disabling: Symfony 7.2 stateless CSRF (token id 'submit')
            // and Live Component's hydrate/dehydrate cycle do not align — the
            // double-submit cookie/field token mismatches on every save.
            // Revisit if the form is ever rendered outside a Live Component.
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
