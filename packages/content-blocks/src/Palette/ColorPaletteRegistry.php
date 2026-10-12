<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Aggregates {@see ColorPaletteProviderInterface} services into one palette,
 * so config-declared colors and host providers surface together.
 */
final class ColorPaletteRegistry implements ResetInterface
{
    /**
     * Read once per request: a host provider may query its database.
     *
     * @var list<PaletteColor>|null
     */
    private ?array $colors = null;

    /**
     * @param iterable<ColorPaletteProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    public function reset(): void
    {
        $this->colors = null;
    }

    /**
     * @return list<PaletteColor>
     */
    public function all(): array
    {
        return $this->colors ??= $this->collect();
    }

    /**
     * The hex a colour is previewed and toned with: its palette entry's
     * `swatch`, else {@see CssColor::swatch()}.
     */
    public function swatch(mixed $color): ?string
    {
        if (\is_string($color)) {
            $key = strtolower(trim($color));
            foreach ($this->all() as $entry) {
                if ($entry->swatch !== null && strtolower($entry->color) === $key) {
                    $swatch = CssColor::swatch($entry->swatch);
                    if ($swatch !== null) {
                        return $swatch;
                    }
                }
            }
        }

        return CssColor::swatch($color);
    }

    /** {@see ColorTone::of()} judged on {@see swatch()}. */
    public function tone(mixed $color): ?string
    {
        return ColorTone::of($this->swatch($color));
    }

    /**
     * @return list<PaletteColor>
     */
    private function collect(): array
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

        // Overwriting an earlier index above keeps the keys contiguous, but
        // only array_values() makes that a list the type system can see.
        return array_values($out);
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
