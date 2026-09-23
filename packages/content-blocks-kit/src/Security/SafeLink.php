<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Security;

/**
 * Whether a stored link may reach an `href`: http(s), mailto, tel, or a
 * scheme-less (relative, `#`, `//`) URL. `javascript:` and `data:` never do.
 */
final class SafeLink
{
    public const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function isSafe(string $url): bool
    {
        // Browsers drop these before reading the scheme: `java\tscript:`.
        $normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $normalized, $m) !== 1) {
            return true;
        }

        return \in_array(strtolower($m[1]), self::SCHEMES, true);
    }

    /** The link itself when safe, '' otherwise. */
    public static function filter(mixed $url): string
    {
        if (!\is_string($url)) {
            return '';
        }

        return self::isSafe($url) ? $url : '';
    }
}
