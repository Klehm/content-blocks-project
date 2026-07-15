<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Form\Type;

use ContentBlocks\Form\Type\ImageUploadType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One card (image, title, text, optional button) inside a
 * {@see \ContentBlocks\Kit\Block\CardBlock}.
 */
final class CardItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('src', ImageUploadType::class, [
                'label' => 'cb_kit.block.image.field.file',
                'translation_domain' => 'content_blocks_kit',
            ])
            ->add('title', TextType::class, [
                'label' => 'cb_kit.block.card.field.item_title',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'cb_kit.block.card.field.item_content',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('url', UrlType::class, [
                'label' => 'cb_kit.block.card.field.item_button_url',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'default_protocol' => null,
                'constraints' => [new Assert\Length(max: 1024)],
            ])
            ->add('buttonText', TextType::class, [
                'label' => 'cb_kit.block.card.field.item_button_label',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 100)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
