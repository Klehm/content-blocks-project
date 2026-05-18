<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Aggregates {@see BlockDataDefaultsProviderInterface} services into a
 * single defaults map and exposes helpers for the two places defaults
 * matter — block-side mirror of
 * {@see \ContentBlocks\Section\SectionSettingsDefaults}:
 *
 *  - {@see get()}             — merged defaults; injected as initial
 *                               form data so widgets without an
 *                               "empty" state (color picker, range
 *                               slider…) don't show browser fallbacks.
 *  - {@see withoutDefaults()} — strips default-equal entries from a
 *                               block's data before it flows to the
 *                               decorator pipeline. Keeps the rendered
 *                               markup uncluttered when the user
 *                               saved values that match the default.
 */
final class BlockDataDefaults
{
    /**
     * @param iterable<BlockDataDefaultsProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            // Recursive merge so providers can declare nested defaults
            // (e.g. ['styling' => ['backgroundColor' => '#ffffff']]).
            $out = array_replace_recursive($out, $provider->getDefaults());
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function withoutDefaults(array $data): array
    {
        return $this->stripDefaults($data, $this->get());
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function stripDefaults(array $data, array $defaults): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $default = $defaults[$key] ?? null;

            if (\is_array($value) && \is_array($default)) {
                $stripped = $this->stripDefaults($value, $default);
                if ($stripped !== []) {
                    $out[$key] = $stripped;
                }
                continue;
            }

            if (\array_key_exists($key, $defaults) && $default === $value) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
