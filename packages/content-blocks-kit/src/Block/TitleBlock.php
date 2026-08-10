<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\Form\Type\PaletteColorType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock(priority: 100)]
class TitleBlock extends AbstractKitBlock implements BlockPreviewHintInterface
{
    public static function getType(): string
    {
        return 'title';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.title.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        // Heading "H".
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
            . 'stroke-linejoin="round"><path d="M6 4v16M18 4v16M6 12h12"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('text', TextType::class, [
                'cb_translatable' => true,
                'label' => 'cb_kit.block.title.field.text',
                'translation_domain' => 'content_blocks_kit',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            // Visual size, decoupled from the semantic element below: an editor
            // can emit a semantically-correct <h2> that *looks* like an h1. The
            // size drives a cb-kit-title--h* class; the tag drives the element.
            ->add('size', ChoiceType::class, [
                'label' => 'cb_kit.block.title.field.size',
                'translation_domain' => 'content_blocks_kit',
                'choices' => $this->choices('size'),
                'constraints' => [$this->choiceConstraint('size')],
            ])
            ->add('tag', ChoiceType::class, [
                'label' => 'cb_kit.block.title.field.tag',
                'translation_domain' => 'content_blocks_kit',
                // Semantic element only — literal labels, not translation keys.
                'choice_translation_domain' => false,
                'choices' => $this->choices('tag'),
                'constraints' => [$this->choiceConstraint('tag')],
            ])
            // Reuses the core palette (content_blocks.palette) — the same named
            // colors as section/block backgrounds and the TinyMCE swatches.
            // Stores a plain '#hex' ('' = inherit the theme's text color).
            ->add('color', PaletteColorType::class, [
                'label' => 'cb_kit.block.field.text_color',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ]);
    }

    protected function choiceFields(): array
    {
        return [
            'size' => [
                'cb_kit.block.title.size.h1' => 'h1',
                'cb_kit.block.title.size.h2' => 'h2',
                'cb_kit.block.title.size.h3' => 'h3',
                'cb_kit.block.title.size.h4' => 'h4',
                'cb_kit.block.title.size.h5' => 'h5',
                'cb_kit.block.title.size.h6' => 'h6',
            ],
            'tag' => [
                'H1' => 'h1',
                'H2' => 'h2',
                'H3' => 'h3',
                'H4' => 'h4',
                'H5' => 'h5',
                'H6' => 'h6',
                'span' => 'span',
                'p' => 'p',
            ],
        ];
    }

    protected function defaults(): array
    {
        return [
            'text' => '',
            'size' => 'h2',
            'tag' => 'h2',
            'color' => '',
        ];
    }

    /**
     * The heading is the block — a library thumbnail showing the real words
     * is what makes one saved hero distinguishable from the next.
     */
    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::heading(self::previewString($data, 'text'));
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/title/view.html.twig';
    }

    // Static markup, no view-side JS — safe to hot-reload in place.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
