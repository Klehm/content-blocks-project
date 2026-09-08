<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * CKEditor 5 classic, selected with `…rich_text.options.editor: ckeditor`. It
 * needs a stylesheet next to its script, which TinyMCE does not.
 *
 * @see docs/internals/kit.md#assets-and-the-asset-prefix
 */
final class CkEditor extends AbstractRichTextEditor
{
    /**
     * The version both default URLs point at — bumping it is a one-constant
     * change, and a host pins its own through `options`.
     */
    public const CDN_VERSION = '48.3.1';

    public static function getName(): string
    {
        return 'ckeditor';
    }

    public static function getController(): string
    {
        return 'cb-ckeditor';
    }

    public static function getDefaultScriptUrl(): string
    {
        return sprintf('https://cdn.ckeditor.com/ckeditor5/%s/ckeditor5.umd.js', self::CDN_VERSION);
    }

    public static function getDefaultStyleUrl(): string
    {
        return sprintf('https://cdn.ckeditor.com/ckeditor5/%s/ckeditor5.css', self::CDN_VERSION);
    }
}
