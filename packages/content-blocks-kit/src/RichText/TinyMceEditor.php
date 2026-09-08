<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * TinyMCE 7, the kit's default editor. Loaded from jsDelivr under the GPL
 * license key; a host with no CDN self-hosts or bundles it.
 *
 * @see docs/internals/kit.md#assets-and-the-asset-prefix
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
