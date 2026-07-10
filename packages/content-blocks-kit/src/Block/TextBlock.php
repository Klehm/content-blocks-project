<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Form\Type\PaletteColorType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock(priority: 90)]
final class TextBlock extends AbstractKitBlock
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
        $builder
            ->add('content', TextareaType::class, [
                'label' => 'cb_kit.block.text.field.content',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
                'attr' => ['rows' => 5],
            ])
            // Reuses the core palette — same named colors as the title block and
            // the TinyMCE swatches. Stores a plain '#hex' ('' = inherit).
            ->add('color', PaletteColorType::class, [
                'label' => 'cb_kit.block.field.text_color',
                'translation_domain' => 'content_blocks_kit',
                'required' => false,
            ]);
    }

    protected function defaults(): array
    {
        return ['content' => '', 'color' => ''];
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
