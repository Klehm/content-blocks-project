<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Normalizes a `json` column read through scalar hydration, which returns it
 * as a raw string — an `is_array()` check there reports zero references.
 *
 * @see docs/internals/assets.md#the-scalar-hydration-trap
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
