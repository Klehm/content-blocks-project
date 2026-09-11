<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

/**
 * Import half of the asset convention: tokens back to the paths this
 * installation stored the bytes at. One instance per import, never a service.
 *
 * @see docs/internals/transfer.md#assets-travel-as-bytes-not-paths
 */
final class AssetRewriter
{
    /**
     * @param array<string, string> $assetMap hash => path it was stored at here
     */
    public function __construct(
        private readonly array $assetMap = [],
    ) {
    }

    /**
     * Rewrites every token, whether it is the whole value or sits inside
     * markup. An unknown hash is left as-is, so the problem surfaces in the UI.
     */
    public function rewrite(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, AssetTokenizer::TOKEN_PREFIX)) {
            $hash = substr($value, \strlen(AssetTokenizer::TOKEN_PREFIX));

            return $this->assetMap[$hash] ?? $value;
        }

        if (is_string($value) && str_contains($value, AssetTokenizer::TOKEN_PREFIX)) {
            return preg_replace_callback(
                '#' . preg_quote(AssetTokenizer::TOKEN_PREFIX, '#') . '([A-Za-z0-9_-]+)#',
                fn (array $m) => $this->assetMap[$m[1]] ?? $m[0],
                $value,
            ) ?? $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->rewrite($v);
            }

            return $out;
        }

        return $value;
    }
}
