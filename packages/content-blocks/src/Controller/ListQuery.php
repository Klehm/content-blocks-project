<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

/**
 * The two query-string reads the paginated pickers share.
 *
 * @internal
 */
final class ListQuery
{
    /** Past this, an offset would overflow an int and throw a TypeError. */
    public const MAX_PAGE = 10_000;

    public static function page(mixed $raw): int
    {
        return min(max(0, is_numeric($raw) ? (int) $raw : 0), self::MAX_PAGE);
    }

    /** A LIKE pattern matching $text anywhere, `%` and `_` read as text. */
    public static function contains(string $text): string
    {
        return '%' . addcslashes($text, '%_\\') . '%';
    }
}
