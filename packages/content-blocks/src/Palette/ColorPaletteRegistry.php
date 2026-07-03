<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * Aggregates {@see ColorPaletteProviderInterface} services into a single
 * palette. Wired with a `tagged_iterator` so config-declared colors and
 * host-registered providers surface together.
 */
final class ColorPaletteRegistry
{
    /**
     * @param iterable<ColorPaletteProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    /**
     * @return list<PaletteColor>
     */
    public function all(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getColors() as $color) {
                // Last provider wins on hex collisions so hosts can re-label
                // a config-declared color from a PHP provider.
                $key = strtolower($color->color);
                if (isset($seen[$key])) {
                    $out[$seen[$key]] = $color;
                    continue;
                }
                $seen[$key] = \count($out);
                $out[] = $color;
            }
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * Form-friendly choices: label => hex.
     *
     * @return array<string, string>
     */
    public function getChoices(): array
    {
        $out = [];
        foreach ($this->all() as $color) {
            $out[$color->label] = $color->color;
        }

        return $out;
    }

    /**
     * Lowercased hex values, for "is this a palette color?" lookups.
     *
     * @return list<string>
     */
    public function getHexes(): array
    {
        return array_map(
            static fn (PaletteColor $c): string => strtolower($c->color),
            $this->all(),
        );
    }
}
