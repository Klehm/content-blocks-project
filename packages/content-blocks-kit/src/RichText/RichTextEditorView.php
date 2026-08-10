<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * What the form theme needs in order to mount an editor: a Stimulus
 * controller name and the values to hand it.
 *
 * Values are keyed by their dashed Stimulus value name (`script-url` →
 * `data-cb-tinymce-script-url-value`) and are already strings — JSON payloads
 * encoded, flags flattened — so the template only interpolates. Keeping the
 * encoding here rather than in Twig is what lets the same theme render any
 * editor, including one a host adds.
 */
final class RichTextEditorView
{
    /**
     * @param string                $controller Stimulus controller name, e.g. `cb-tinymce`
     * @param array<string, string> $values     Dashed value name => rendered value
     */
    public function __construct(
        public readonly string $controller,
        public readonly array $values = [],
    ) {
    }
}
