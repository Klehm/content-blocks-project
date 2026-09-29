<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * Whether a localized image or video value may reach a `src`: a scheme-less
 * path or an http(s) URL, the shapes the upload widget itself stores.
 */
final class MediaPath
{
    public const MAX_LENGTH = 1024;

    public static function isSafe(string $value): bool
    {
        if ($value === '' || \strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // Browsers drop these before reading the scheme: `java\tscript:`.
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return false;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $value, $m) !== 1) {
            return true;
        }

        return \in_array(strtolower($m[1]), ['http', 'https'], true);
    }
}
