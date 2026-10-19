<?php

declare(strict_types=1);

namespace ContentBlocks\PublicAsset;

/**
 * The cascade layer the public stylesheets are served in, from
 * `content_blocks.css_layer`. Null: unlayered, as before the option.
 *
 * @see docs/internals/assets.md#the-public-css-in-a-cascade-layer
 */
final class CssLayer
{
    /** A layer name, dotted for a sub-layer: `content-blocks`, `vendor.cb`. */
    public const NAME_PATTERN = '/^-?[A-Za-z_][A-Za-z0-9_-]*(\.-?[A-Za-z_][A-Za-z0-9_-]*)*$/';

    public function __construct(
        public readonly ?string $name = null,
    ) {
        if ($name !== null && preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a CSS layer name.', $name));
        }
    }

    public function wrap(string $css): string
    {
        return $this->name === null ? $css : "@layer {$this->name} {\n{$css}\n}\n";
    }
}
