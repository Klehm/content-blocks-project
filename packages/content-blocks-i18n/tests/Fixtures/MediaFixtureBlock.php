<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Fixtures;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\VideoUploadType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A block with a tagged image, a tagged video and a tagged caption, the shape
 * of the kit's `image` and `video` blocks.
 */
final class MediaFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'media_fixture';
    }

    public function getLabel(): string
    {
        return 'Media fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('src', ImageUploadType::class, ['cb_translatable' => true])
            ->add('video', VideoUploadType::class, ['cb_translatable' => true])
            ->add('caption', TextType::class, [
                'required' => false,
                'cb_translatable' => true,
            ]);
    }

    public function getDefaultData(): array
    {
        return ['src' => '', 'video' => '', 'caption' => ''];
    }
}
