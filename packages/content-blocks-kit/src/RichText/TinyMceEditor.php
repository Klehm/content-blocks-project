<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * TinyMCE 7 — the kit's default rich-text editor, and the one the block has
 * shipped with since the beginning.
 *
 * Loaded from jsDelivr by default and initialized under the GPL license key.
 * A host that cannot reach a CDN either self-hosts the same build
 * (`options.cdn_url`) or bundles TinyMCE itself (`options.cdn: false`) — see
 * the kit configuration guide.
 */
final class TinyMceEditor extends AbstractRichTextEditor
{
    public static function getName(): string
    {
        return 'tinymce';
    }

    public static function getController(): string
    {
        return 'cb-tinymce';
    }

    public static function getDefaultScriptUrl(): string
    {
        return 'https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js';
    }
}
