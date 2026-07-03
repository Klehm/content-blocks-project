<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Form\Type\ImageUploadType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock(priority: 70)]
final class ImageBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'image';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.image.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        // Framed picture with horizon + sun.
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" '
            . 'stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/>'
            . '<circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 16l-5-5-7 7"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            // Upload UI (file picker + preview) rendered by the main
            // package's cb_image_upload widget — no form theme needed.
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
            ->add('width', RangeType::class, [
                'label' => 'cb_kit.block.image.field.width',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['min' => 0, 'max' => 1200, 'step' => 10],
            ])
            ->add('height', RangeType::class, [
                'label' => 'cb_kit.block.image.field.height',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['min' => 0, 'max' => 1200, 'step' => 10],
            ]);
    }

    public function getDefaultData(): array
    {
        return [
            'src' => '',
            'alt' => '',
            'width' => 0,
            'height' => 0,
        ];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/image/view.html.twig';
    }

    // Static <img> markup; the upload JS lives in the edit form, not the
    // view — safe to hot-reload in place.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
