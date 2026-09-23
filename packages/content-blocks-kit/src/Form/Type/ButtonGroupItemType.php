<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use ContentBlocks\Kit\Security\SafeLinkConstraint;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One button inside a {@see \ContentBlocks\Kit\Block\ButtonGroupBlock}. The
 * block hands down its variant choices, so config reaches each entry.
 */
final class ButtonGroupItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.button.field.text',
                'translation_domain' => 'content_blocks_kit',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('url', UrlType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.button.field.href',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'default_protocol' => null,
                'constraints' => [new Assert\Length(max: 1024), new SafeLinkConstraint()],
            ])
            ->add('variant', ChoiceType::class, [
                'label' => 'cb_kit.block.button.field.variant',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $options['variant_choices'],
                'constraints' => $options['variant_constraint'] !== null ? [$options['variant_constraint']] : [],
            ])
            ->add('newTab', CheckboxType::class, [
                'label' => 'cb_kit.block.button.field.new_tab',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'variant_choices' => [
                'cb_kit.block.button.variant.primary' => 'primary',
                'cb_kit.block.button.variant.secondary' => 'secondary',
                'cb_kit.block.button.variant.outline' => 'outline',
                'cb_kit.block.button.variant.link' => 'link',
            ],
            'variant_constraint' => null,
        ]);
        $resolver->setAllowedTypes('variant_choices', 'array');
        $resolver->setAllowedTypes('variant_constraint', ['null', Assert\Choice::class]);
    }
}
