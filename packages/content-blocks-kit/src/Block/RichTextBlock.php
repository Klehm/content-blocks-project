<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\Kit\Form\Type\RichTextEditorType;
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

    /**
     * Which editor mounts the field, and how it is loaded and configured.
     * The stored payload is the same HTML under every editor, so `editor` is
     * a display-time choice: flipping it does not touch a single row.
     */
    public static function defaultOptions(): array
    {
        return [
            // Any name in the RichTextEditorRegistry — `tinymce`, `ckeditor`,
            // or one the host registered.
            'editor' => 'tinymce',
            // false → the kit loads no editor JS; the host bundles it and the
            // editor's global must be present when the form opens.
            'cdn' => true,
            // Override to self-host the same build (air-gapped admin, strict CSP).
            'cdn_url' => null,
            'cdn_style_url' => null,
            // Wires the editor's image button to the builder's upload endpoint.
            'uploads' => true,
            // Merged over the adapter's coded init config, in the browser.
            'config' => [],
        ];
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
        // The textarea is enhanced client-side by whichever editor's Stimulus
        // controller the adapter names. With JS disabled — or an editor that
        // fails to load — the user still gets a plain textarea holding the
        // same HTML.
        $builder->add('content', RichTextEditorType::class, [
            'cb_translatable' => true,
            'label' => 'cb_kit.block.rich_text.field.content',
            'translation_domain' => 'content_blocks_kit',
            // Resolved rather than raw: a block built outside the bundle's
            // merge (a unit test, a host instantiating it directly) still
            // hands the adapter a complete option set.
            'editor_options' => array_replace(static::defaultOptions(), $this->options),
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

    // The view renders stored HTML statically; the editor only runs in the
    // edit form — safe to hot-reload in place.
    public function supportsPreviewHotReload(): bool
    {
        return true;
    }
}
