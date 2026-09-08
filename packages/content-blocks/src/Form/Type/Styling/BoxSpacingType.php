<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type\Styling;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Four sides plus a `linked` flag, persisted so reopening the sidebar
 * restores the UX state. The sync itself is a Stimulus concern.
 *
 * @see docs/internals/forms.md#the-responsive-styling-sub-types
 */
final class BoxSpacingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sides = ['top', 'right', 'bottom', 'left'];
        foreach ($sides as $side) {
            $builder->add($side, IntegerType::class, [
                'required' => false,
                'attr' => ['min' => $options['allow_negative'] ? null : 0],
            ]);
        }

        $builder->add('linked', CheckboxType::class, [
            'required' => false,
            'false_values' => ['0', '', null],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'allow_negative' => false,
            'translation_domain' => 'content_blocks',
        ]);
        $resolver->setAllowedTypes('allow_negative', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'cb_box_spacing';
    }
}
