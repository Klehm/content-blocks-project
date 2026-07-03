<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use ContentBlocks\Form\Type\ImageUploadType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One image (upload + alt + optional caption and link) inside a
 * {@see \ContentBlocks\Kit\Block\GalleryBlock}.
 */
final class GalleryItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('src', ImageUploadType::class, [
                'label' => 'cb_kit.block.image.field.file',
                'translation_domain' => 'content_blocks_kit',
            ])
            ->add('alt', TextType::class, [
                'label' => 'cb_kit.block.image.field.alt',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('caption', TextType::class, [
                'label' => 'cb_kit.block.gallery.field.item_caption',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('link', UrlType::class, [
                'label' => 'cb_kit.block.image.field.link',
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
