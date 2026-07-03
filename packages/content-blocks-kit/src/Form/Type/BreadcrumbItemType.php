<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One step inside a {@see \ContentBlocks\Kit\Block\BreadcrumbBlock}. An empty
 * url marks the current page (rendered as text, not a link).
 */
final class BreadcrumbItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'cb_kit.block.breadcrumb.field.item_label',
                'translation_domain' => 'content_blocks_kit',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('url', UrlType::class, [
                'label' => 'cb_kit.block.breadcrumb.field.item_url',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'default_protocol' => null,
                'constraints' => [new Assert\Length(max: 1024)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
