<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * Fingerprint of a source value, stored next to the translation written from
 * it. Byte-exact by design; 16 hex characters of SHA-256.
 *
 * @see docs/internals/i18n.md#the-digest-is-the-whole-mechanism
 */
final class SourceDigest
{
    private const LENGTH = 16;

    public static function of(mixed $value): string
    {
        // Encoded rather than cast, so a host tagging something that is not a
        // string gets a stable representation instead of "Array".
        $canonical = \is_string($value)
            ? $value
            : (json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');

        return substr(hash('sha256', $canonical), 0, self::LENGTH);
    }

    public static function matches(mixed $value, ?string $digest): bool
    {
        // Absence predates the mechanism, or came from an import. The
        // pessimistic reading has to be earned by an actual mismatch.
        if ($digest === null || $digest === '') {
            return true;
        }

        return hash_equals($digest, self::of($value));
    }
}
