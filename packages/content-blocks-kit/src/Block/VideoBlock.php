<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\VideoUploadType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A self-hosted video file in a native `<video>`, with poster, playback
 * options and caption. YouTube and Vimeo belong to the `embed` block.
 *
 * @see docs/internals/kit.md#video-a-file-not-a-provider
 */
#[AsContentBlock(priority: 32)]
class VideoBlock extends AbstractKitBlock implements BlockPreviewHintInterface
{
    public static function getType(): string
    {
        return 'video';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.video.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        // Film strip with a play triangle.
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" '
            . 'stroke-linejoin="round"><rect x="2" y="5" width="15" height="14" rx="2"/>'
            . '<path d="m17 10 5-3v10l-5-3z"/><path d="m8 9.5 4 2.5-4 2.5z"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('src', VideoUploadType::class, [
                'label' => 'cb_kit.block.video.field.file',
                'translation_domain' => 'content_blocks_kit',
                'help' => 'cb_kit.block.video.file_help',
            ])
            ->add('poster', ImageUploadType::class, [
                'label' => 'cb_kit.block.video.field.poster',
                'translation_domain' => 'content_blocks_kit',
            ])
            // Autoplay is only allowed muted by browsers, so muting is
            // offered only when the editor has a choice.
            ->add('autoplay', CheckboxType::class, [
                'label' => 'cb_kit.block.video.field.autoplay',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ])
            ->add('muted', CheckboxType::class, [
                'label' => 'cb_kit.block.video.field.muted',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'row_attr' => ['data-cb-condition' => 'autoplay:false'],
            ])
            // Without autoplay the controls are the only way to start it.
            ->add('controls', CheckboxType::class, [
                'label' => 'cb_kit.block.video.field.controls',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'row_attr' => ['data-cb-condition' => 'autoplay:true'],
            ])
            ->add('loop', CheckboxType::class, [
                'label' => 'cb_kit.block.video.field.loop',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ])
            ->add('size', ChoiceType::class, [
                'label' => 'cb_kit.block.video.field.size',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('size'),
                'constraints' => [$this->choiceConstraint('size')],
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'cb_kit.block.field.align',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('align'),
                'constraints' => [$this->choiceConstraint('align')],
            ])
            ->add('caption', TextType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.video.field.caption',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
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
            ],
            'align' => $this->alignChoices(),
        ];
    }

    protected function defaults(): array
    {
        return [
            'src' => '',
            'poster' => '',
            'autoplay' => false,
            'muted' => false,
            'controls' => true,
            'loop' => false,
            'size' => 'full',
            'align' => 'center',
            'caption' => '',
        ];
    }

    /** The poster is the only picture a list endpoint can show. */
    public function previewHint(array $data): ?BlockPreviewHint
    {
        $poster = trim(self::previewString($data, 'poster') ?? '');
        $caption = self::previewString($data, 'caption');

        return $poster !== ''
            ? BlockPreviewHint::image($poster, $caption)
            : BlockPreviewHint::generic($caption);
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/video/view.html.twig';
    }

    // A native element: autoplay applies on insertion, nothing to boot.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
