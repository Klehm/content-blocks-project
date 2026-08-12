<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * CKEditor 5 (classic build), selected with
 * `content_blocks_kit.blocks.rich_text.options.editor: ckeditor`.
 *
 * Unlike TinyMCE, CKEditor 5 needs a stylesheet next to its script — the CDN
 * ships `ckeditor5.css` separately — hence the second asset URL. Both are
 * pinned to one version: the editor's factory signature changed in 48
 * (`create({attachTo})` supersedes the now-deprecated `create(element)`), and
 * the controller picks its call shape from `window.CKEDITOR_VERSION`, so an
 * older self-hosted build still boots.
 */
final class CkEditor extends AbstractRichTextEditor
{
    /**
     * The CDN version both default URLs point at. Bumping it is a
     * one-constant change; hosts pin their own via `options.cdn_url` /
     * `options.cdn_style_url`.
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

    public static function getDefaultStyleUrl(): ?string
    {
        return sprintf('https://cdn.ckeditor.com/ckeditor5/%s/ckeditor5.css', self::CDN_VERSION);
    }
}
