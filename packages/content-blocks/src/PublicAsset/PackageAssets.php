<?php

declare(strict_types=1);

namespace ContentBlocks\PublicAsset;

/**
 * The files `AssetController` serves, by name, so a template can put their
 * version in the URL it links.
 *
 * @internal
 */
final class PackageAssets
{
    private const CSS = 'text/css; charset=UTF-8';
    private const JS = 'application/javascript; charset=UTF-8';

    /**
     * Prepended, not @import-ed, for the builder: served raw, an @import would
     * resolve against the public mount, where no route serves the tokens.
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const FILES = [
        'layout' => [['/styles/layout.css'], self::CSS],
        'styling' => [['/styles/styling.css'], self::CSS],
        'builder' => [['/styles/tokens.css', '/styles/builder.css'], self::CSS],
        'slider' => [['/slider.js'], self::JS],
        'preview_overlay' => [['/preview-overlay.js'], self::JS],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::FILES);
    }

    /** Null when a file is missing. */
    public static function content(string $name): ?string
    {
        $parts = [];
        foreach (self::FILES[$name][0] ?? [] as $file) {
            $part = @file_get_contents(\dirname(__DIR__, 2) . '/assets' . $file);
            if ($part === false) {
                return null;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    public static function contentType(string $name): string
    {
        return self::FILES[$name][1] ?? self::CSS;
    }
}
