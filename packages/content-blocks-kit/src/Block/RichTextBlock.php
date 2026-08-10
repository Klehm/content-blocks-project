<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsContentBlock(priority: 80)]
class RichTextBlock extends AbstractKitBlock implements BlockPreviewHintInterface
{
    public static function getType(): string
    {
        return 'rich_text';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('cb_kit.block.rich_text.label', [], 'content_blocks_kit');
    }

    public static function getIcon(): ?string
    {
        // Pilcrow — formatted/rich text.
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" '
            . 'stroke-linejoin="round"><path d="M13 4v16M17 4v16M13 4h-3a4 4 0 0 0 0 8h3"/>'
            . '<path d="M5 20h6"/></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        // The TextareaType is enhanced client-side by the cb-tinymce
        // Stimulus controller (declared in the form theme). When JS is
        // disabled, the user still gets a plain textarea fallback.
        $builder->add('content', TextareaType::class, [
            'cb_translatable' => true,
            'label' => 'cb_kit.block.rich_text.field.content',
            'translation_domain' => 'content_blocks_kit',
            'required' => false,
            'attr' => ['rows' => 10],
        ]);
    }

    protected function defaults(): array
    {
        return ['content' => ''];
    }

    public function getFormTheme(): ?string
    {
        return '@ContentBlocksKit/form/rich_text_theme.html.twig';
    }

    /**
     * The stored content is HTML. Tags are stripped rather than rendered: a
     * thumbnail tile shows a line of plain copy, and injecting editor markup
     * into the admin's DOM is not something a preview should ever do.
     */
    public function previewHint(array $data): ?BlockPreviewHint
    {
        $html = self::previewString($data, 'content');

        return BlockPreviewHint::text($html === null ? null : strip_tags($html));
    }

    public function getViewTemplate(): ?string
    {
        return '@ContentBlocksKit/block/rich_text/view.html.twig';
    }

    // The view renders stored HTML statically; TinyMCE only runs in the
    // edit form — safe to hot-reload in place.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
