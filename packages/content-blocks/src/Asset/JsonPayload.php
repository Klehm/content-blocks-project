<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Normalizes a `json` column read through scalar hydration.
 *
 * The trap this exists for, found by running the sweep against a real
 * database: `SELECT b.publishedData AS published FROM Block b` hydrated with
 * `HYDRATE_SCALAR` returns the column **as its raw JSON string**, not as the
 * array the entity getter would hand back — the type conversion that
 * `getPublishedData()` benefits from does not run on an aliased scalar.
 *
 * A reference provider that simply checked `is_array()` therefore skipped
 * every row and reported *no* references at all, which in a sweep means
 * "delete everything". The failure is silent and it points the wrong way, so
 * the decoding lives here, once, rather than in each provider.
 */
final class JsonPayload
{
    /**
     * @return array<array-key, mixed> the decoded payload, or an empty array
     *                                 for null, a non-JSON string, or a scalar
     */
    public static function decode(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
