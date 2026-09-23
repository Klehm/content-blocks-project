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
     * The version both default URLs point at. Bump the two SRI hashes with
     * it; a host pins its own through `options`.
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

    public static function getDefaultScriptIntegrity(): string
    {
        return 'sha384-akAwX6iEF9BHp8Dy2yzl4ObUCE2yI9XeQT0HuCgyhj4PMwRK3gG5JJcfWM2A36+c';
    }

    public static function getDefaultStyleIntegrity(): string
    {
        return 'sha384-JLhKKU4cpXIS4w5MAZD6QWLZK/q8o+pJH18jXM9KLezveA9zYubeE7MnFofSe++S';
    }
}
