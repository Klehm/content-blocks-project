<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Aggregates {@see SectionSettingsDefaultsProviderInterface} services into
 * a single defaults map and exposes helpers for the two places defaults
 * matter:
 *
 *  - {@see get()}                 — merged defaults; injected as initial
 *                                   form data so widgets without an
 *                                   "empty" state (color picker, range
 *                                   slider…) don't show browser fallbacks.
 *  - {@see withoutDefaults()}     — strips default-equal entries from a
 *                                   settings array before it flows to the
 *                                   decorator pipeline. Keeps the rendered
 *                                   markup uncluttered when the user
 *                                   saved values that match the default.
 */
final class SectionSettingsDefaults
{
    /**
     * @param iterable<SectionSettingsDefaultsProviderInterface> $providers
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
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function withoutDefaults(array $settings): array
    {
        return $this->stripDefaults($settings, $this->get());
    }

    /**
     * Recursively strip values equal to the default. When a nested array
     * becomes empty after stripping, the key itself is removed too — the
     * rendered markup only carries the user's actual overrides.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function stripDefaults(array $settings, array $defaults): array
    {
        $out = [];
        foreach ($settings as $key => $value) {
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
