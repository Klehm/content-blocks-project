<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Image field for block forms: a picker and preview around a hidden input
 * holding the stored path, driven by `cb-file-upload`.
 *
 * @see \ContentBlocks\Storage\FileStorageInterface required, else uploads throw
 */
final class ImageUploadType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['accept'] = $options['accept'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'translation_domain' => 'content_blocks',
            // The `accept` attribute of the rendered file picker.
            'accept' => 'image/*',
            'attr' => ['data-cb-file-upload-target' => 'hiddenInput'],
        ]);
        $resolver->setAllowedTypes('accept', 'string');
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cb_image_upload';
    }
}
