<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * Fingerprint of a source value, stored next to the translation written from it.
 *
 * This is the entire staleness mechanism: hash the English when the German is
 * saved, re-hash the English when the page is inspected, and a mismatch means
 * the English moved. No timestamps, no revision numbers, no diffing — and
 * crucially, nothing that a save of an *unrelated* field can perturb.
 *
 * Byte-exact by design. A whitespace-only edit to the source is a change: the
 * editor decides whether it matters, and a "mark as up to date" click costs one
 * second, whereas normalizing away a real edit costs a wrong page.
 *
 * 16 hex characters of SHA-256. Long enough that an accidental collision — two
 * different source texts hashing alike, silently hiding a stale translation —
 * is not a thing that happens; short enough that the digest map stays smaller
 * than the values it annotates.
 */
final class SourceDigest
{
    private const LENGTH = 16;

    public static function of(mixed $value): string
    {
        // Encoded rather than cast: a tagged field is text in every case we
        // know of, but a host is free to tag something else, and json_encode
        // gives arrays and numbers a stable representation instead of throwing
        // or stringifying to "Array".
        $canonical = \is_string($value)
            ? $value
            : (json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');

        return substr(hash('sha256', $canonical), 0, self::LENGTH);
    }

    public static function matches(mixed $value, ?string $digest): bool
    {
        // A translation stored without a digest predates this mechanism (or was
        // written by an import). Reporting it as stale would flood the workbench
        // with false alarms on day one, so absence reads as "no reason to doubt
        // it" — the pessimistic reading has to be earned by an actual mismatch.
        if ($digest === null || $digest === '') {
            return true;
        }

        return hash_equals($digest, self::of($value));
    }
}
