<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Video file field: the image upload widget with a `<video>` preview, so the
 * same endpoint, drop zone and pasted path serve a video.
 *
 * @see \ContentBlocks\Storage\FileStorageInterface required, else uploads throw
 */
final class VideoUploadType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['preview_kind'] = 'video';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'accept' => 'video/mp4,video/webm,video/ogg',
        ]);
    }

    public function getParent(): string
    {
        return ImageUploadType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cb_video_upload';
    }
}
