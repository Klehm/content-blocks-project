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
            $out = array_replace($out, $provider->getDefaults());
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function withoutDefaults(array $settings): array
    {
        $defaults = $this->get();
        $out = [];
        foreach ($settings as $key => $value) {
            if (\array_key_exists($key, $defaults) && $defaults[$key] === $value) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
