<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use ContentBlocks\Storage\MediaLibraryInterface;
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
 * @see docs/guide/media-library.md the *Browse library* button
 */
final class ImageUploadType extends AbstractType
{
    public function __construct(
        private readonly ?MediaLibraryInterface $mediaLibrary = null,
    ) {
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['accept'] = $options['accept'];
        $view->vars['preview_kind'] = 'image';
        $view->vars['alt_field'] = null;

        if ($options['cb_alt_field'] !== null) {
            $parent = $view->parent?->vars['full_name'] ?? '';
            $view->vars['alt_field'] = $parent === ''
                ? $options['cb_alt_field']
                : $parent.'['.$options['cb_alt_field'].']';
        }
    }

    // After the child types' buildView, so a video field asks for `video`.
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['media_library'] = $this->mediaLibrary?->supports(
            $view->vars['preview_kind'],
        ) ?? false;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'translation_domain' => 'content_blocks',
            // The `accept` attribute of the rendered file picker.
            'accept' => 'image/*',
            // A sibling field filled from the library's alt, when empty.
            'cb_alt_field' => null,
            'attr' => ['data-cb-file-upload-target' => 'hiddenInput'],
        ]);
        $resolver->setAllowedTypes('accept', 'string');
        $resolver->setAllowedTypes('cb_alt_field', ['null', 'string']);
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
