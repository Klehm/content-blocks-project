<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Block;

use ContentBlocks\BlockType\AbstractBlockType;

/**
 * Base class for kit blocks. Adds host-configurable per-block options on
 * top of the core {@see AbstractBlockType}.
 *
 * A block declares its option defaults in {@see defaultOptions()}; the host
 * overrides them under `content_blocks_kit.blocks.<type>.options`. The kit
 * bundle merges the two and injects the result as the `$options` constructor
 * argument, so at runtime a block reads a fully-populated option set via
 * {@see option()} — no null-coalescing against defaults needed.
 *
 * Blocks with no options simply don't override {@see defaultOptions()};
 * they still get the constructor for uniform registration.
 */
abstract class AbstractKitBlock extends AbstractBlockType
{
    /**
     * @param array<string, mixed> $options Merged option set (defaults +
     *                                      host overrides), injected by the
     *                                      kit bundle at registration time.
     */
    public function __construct(
        protected readonly array $options = [],
    ) {
    }

    /**
     * Default option values for this block. The host's
     * `content_blocks_kit.blocks.<type>.options` are merged over these.
     *
     * @return array<string, mixed>
     */
    public static function defaultOptions(): array
    {
        return [];
    }

    /**
     * Read a resolved option, falling back to the coded default (so the
     * block is robust even if constructed without the bundle's merge, e.g.
     * in a unit test).
     */
    protected function option(string $key): mixed
    {
        return $this->options[$key] ?? static::defaultOptions()[$key] ?? null;
    }
}
