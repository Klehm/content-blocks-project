<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Upload-driven image field for block forms: renders a file picker +
 * preview around a hidden input holding the stored public path. The
 * `cb-file-upload` Stimulus controller POSTs the picked file to the
 * builder's upload endpoint and writes the returned URL into the hidden
 * input (dispatching `change` so autosave picks it up).
 *
 * The widget markup lives in `cb_form_theme.html.twig`
 * (`cb_image_upload_widget`), which is stacked in the block sidebar by
 * default — no per-block form theme needed:
 *
 *     $builder->add('src', ImageUploadType::class, [
 *         'label' => 'cb.upload.file',
 *     ]);
 *
 * Requires a configured storage backend (see FileStorageInterface).
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
