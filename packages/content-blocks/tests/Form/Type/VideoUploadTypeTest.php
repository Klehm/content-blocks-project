<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\VideoUploadType;
use Symfony\Component\Form\Test\TypeTestCase;

final class VideoUploadTypeTest extends TypeTestCase
{
    public function testStoresThePathAsAPlainString(): void
    {
        $form = $this->factory->create(VideoUploadType::class);

        $form->submit('/uploads/content-blocks/blocks/clip.mp4');

        $this->assertSame('/uploads/content-blocks/blocks/clip.mp4', $form->getData());
    }

    /** The widget is the image one; only the preview element differs. */
    public function testViewReusesTheImageWidgetWithAVideoPreview(): void
    {
        $view = $this->factory->create(VideoUploadType::class)->createView();

        $this->assertSame(['form', 'hidden', 'cb_image_upload', 'cb_video_upload'], \array_slice(
            $view->vars['block_prefixes'],
            0,
            4,
        ));
        $this->assertSame('video', $view->vars['preview_kind']);
        $this->assertSame('video/mp4,video/webm,video/ogg', $view->vars['accept']);
        $this->assertSame('hiddenInput', $view->vars['attr']['data-cb-file-upload-target']);
    }

    public function testTheImageTypeKeepsAnImagePreview(): void
    {
        $view = $this->factory->create(ImageUploadType::class)->createView();

        $this->assertSame('image', $view->vars['preview_kind']);
    }
}
