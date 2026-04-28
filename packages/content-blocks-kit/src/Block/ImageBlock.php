<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock]
final class ImageBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'image';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.image.label', [], 'content_blocks_kit');
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('src', HiddenType::class, [
                'attr' => ['data-cb-file-upload-target' => 'hiddenInput'],
            ])
            ->add('alt', TextType::class, [
                'label' => 'cb_kit.block.image.field.alt',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ]);
    }

    public function getDefaultData(): array
    {
        return [
            'src' => '',
            'alt' => '',
        ];
    }

    public function getFormTheme(): ?string
    {
        return '@ContentBlocksKit/form/image_theme.html.twig';
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/image/view.html.twig';
    }
}
