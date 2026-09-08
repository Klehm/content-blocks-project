<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * A controller name and already-rendered values, keyed by dashed Stimulus name
 * — so one theme can mount any editor, including one a host adds.
 *
 * @see docs/internals/kit.md#rich-text-one-payload-several-editors
 */
final class RichTextEditorView
{
    /**
     * @param string                $controller e.g. `cb-tinymce`
     * @param array<string, string> $values     dashed name => rendered value
     */
    public function __construct(
        public readonly string $controller,
        public readonly array $values = [],
    ) {
    }
}
