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
    /** Pinned with its SRI hash: bump both together. */
    public const CDN_VERSION = '7.9.3';

    public function getName(): string
    {
        return 'tinymce';
    }

    public static function getController(): string
    {
        return 'cb-tinymce';
    }

    public static function getDefaultScriptUrl(): string
    {
        return sprintf('https://cdn.jsdelivr.net/npm/tinymce@%s/tinymce.min.js', self::CDN_VERSION);
    }

    public static function getDefaultScriptIntegrity(): string
    {
        return 'sha384-Ovv1ZPEkpW4ElBKDKaEIPkNfTTadFpifFwNJOBnuStg0PQ0RBln5Lsf9AI8BsCmx';
    }
}
