<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\Styling\BoxSpacingType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One image with sizing, alignment, link, caption and rounded corners. A plain
 * `<img>` at the chosen display size, with no image-processing dependency.
 *
 * @see docs/internals/kit.md#blocks-are-autonomous
 */
#[AsContentBlock(priority: 70)]
class ImageBlock extends AbstractKitBlock implements BlockPreviewHintInterface
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
                'cb_translatable' => true,
                'label' => 'cb_kit.block.image.field.alt',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            // Revealed only for the "custom" size, and the height also
            // hides under "auto height" — two cb-condition clauses ANDed.
            ->add('size', ChoiceType::class, [
                'label' => 'cb_kit.block.image.field.size',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('size'),
                'constraints' => [$this->choiceConstraint('size')],
            ])
            ->add('customWidth', RangeType::class, [
                'label' => 'cb_kit.block.image.field.width',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'row_attr' => ['data-cb-condition' => 'size:custom'],
                'attr' => ['min' => 16, 'max' => 1920, 'step' => 1],
            ])
            ->add('customHeightAuto', CheckboxType::class, [
                'label' => 'cb_kit.block.image.field.height_auto',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'row_attr' => ['data-cb-condition' => 'size:custom'],
            ])
            ->add('customHeight', RangeType::class, [
                'label' => 'cb_kit.block.image.field.height',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'row_attr' => ['data-cb-condition' => 'size:custom;customHeightAuto:false'],
                'attr' => ['min' => 16, 'max' => 1920, 'step' => 10],
            ])
            ->add('fit', ChoiceType::class, [
                'label' => 'cb_kit.block.image.field.fit',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('fit'),
                'constraints' => [$this->choiceConstraint('fit')],
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'cb_kit.block.field.align',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('align'),
                'constraints' => [$this->choiceConstraint('align')],
            ])
            ->add('url', UrlType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.image.field.link',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'default_protocol' => null,
                'constraints' => [new Assert\Length(max: 1024)],
            ])
            ->add('caption', TextType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.image.field.caption',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('borderRadius', BoxSpacingType::class, [
                'label' => 'cb_kit.block.image.field.border_radius',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ]);
    }

    protected function choiceFields(): array
    {
        return [
            'size' => [
                'cb_kit.block.image.size.sm' => 'sm',
                'cb_kit.block.image.size.md' => 'md',
                'cb_kit.block.image.size.lg' => 'lg',
                'cb_kit.block.image.size.full' => 'full',
                'cb_kit.block.image.size.custom' => 'custom',
            ],
            'fit' => [
                'cb_kit.block.image.fit.cover' => 'cover',
                'cb_kit.block.image.fit.contain' => 'contain',
            ],
            'align' => $this->alignChoices(),
        ];
    }

    protected function defaults(): array
    {
        return [
            'src' => '',
            'alt' => '',
            'size' => 'md',
            'customWidth' => 600,
            'customHeightAuto' => true,
            'customHeight' => 400,
            'fit' => 'cover',
            'align' => 'center',
            'url' => '',
            'caption' => '',
            'borderRadius' => ['linked' => true],
        ];
    }

    /**
     * `src` is a storage path, so the tile shows the actual picture.
     *
     * @see docs/internals/kit.md#preview-hints-in-the-kit
     */
    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::image(
            self::previewString($data, 'src') ?? '',
            self::previewString($data, 'caption') ?? self::previewString($data, 'alt'),
        );
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
