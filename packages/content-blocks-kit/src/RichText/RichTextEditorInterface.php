<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * One WYSIWYG editor the `rich_text` block can be driven by.
 *
 * The block itself is editor-agnostic: whichever editor runs, it reads and
 * writes the same `{ content: "<html>" }` payload, so switching editors is a
 * config change (`content_blocks_kit.blocks.rich_text.options.editor`) and
 * never a data migration. That is the whole reason this is a seam rather than
 * a second block type — the editor is an integrator's preference, not a
 * content shape, and it has no business being encoded in `cb_block.type`.
 *
 * Implementations are auto-tagged (see ContentBlocksKitBundle::build()), so a
 * host wires a third editor — Quill, Trix, ProseMirror — by declaring a
 * service implementing this interface plus a Stimulus controller. Nothing in
 * the kit needs to change, and the block, the form type and the form theme
 * stay as they are.
 */
interface RichTextEditorInterface
{
    /**
     * The name hosts select this editor by in configuration
     * (`options.editor: tinymce`). Static so the registry can index
     * implementations without instantiating them.
     */
    public static function getName(): string;

    /**
     * Everything the browser needs to mount this editor, derived from the
     * block's resolved options: which Stimulus controller to attach, and the
     * values that controller reads.
     *
     * @param array<string, mixed> $options The `rich_text` block's resolved option set
     */
    public function buildView(array $options): RichTextEditorView;
}
