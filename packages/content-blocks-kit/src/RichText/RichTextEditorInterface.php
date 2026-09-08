<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * One WYSIWYG editor the `rich_text` block can be driven by. Autoconfigured,
 * and a seam rather than a second block type because it is not a content shape.
 *
 * @see docs/internals/kit.md#rich-text-one-payload-several-editors
 */
interface RichTextEditorInterface
{
    /**
     * The name hosts select this editor by. Static, so the registry indexes
     * implementations without instantiating them.
     */
    public static function getName(): string;

    /**
     * Everything the browser needs to mount this editor: which controller to
     * attach, and the values it reads.
     *
     * @param array<string, mixed> $options the block's resolved option set
     */
    public function buildView(array $options): RichTextEditorView;
}
