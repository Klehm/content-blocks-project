<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock]
final class TextBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'text';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.text.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        // Paragraph lines.
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round">'
            . '<path d="M4 6h16M4 10h16M4 14h12M4 18h8"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('content', TextareaType::class, [
            'label' => 'cb_kit.block.text.field.content',
            'translation_domain' => 'content_blocks_kit',
            'required' => false,
            'attr' => ['rows' => 5],
        ]);
    }

    public function getDefaultData(): array
    {
        return ['content' => ''];
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/text/view.html.twig';
    }

    // Static markup, no view-side JS — safe to hot-reload in place.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
