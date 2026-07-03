<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Form\Type\ImageUploadType;
use Symfony\Component\Form\Test\TypeTestCase;

final class ImageUploadTypeTest extends TypeTestCase
{
    public function testStoresThePathAsAPlainString(): void
    {
        $form = $this->factory->create(ImageUploadType::class, '/uploads/content-blocks/blocks/a.png');

        $form->submit('/uploads/content-blocks/blocks/b.png');

        $this->assertSame('/uploads/content-blocks/blocks/b.png', $form->getData());
    }

    public function testViewCarriesTheUploadTargetAndAcceptAttribute(): void
    {
        $view = $this->factory->create(ImageUploadType::class)->createView();

        // The hidden input is the cb-file-upload controller's write target.
        $this->assertSame('hiddenInput', $view->vars['attr']['data-cb-file-upload-target']);
        // Default accept forwarded to the rendered file picker.
        $this->assertSame('image/*', $view->vars['accept']);
        $this->assertContains('cb_image_upload', $view->vars['block_prefixes']);
    }

    public function testAcceptOptionIsForwarded(): void
    {
        $view = $this->factory
            ->create(ImageUploadType::class, null, ['accept' => 'image/png,application/pdf'])
            ->createView();

        $this->assertSame('image/png,application/pdf', $view->vars['accept']);
    }
}
