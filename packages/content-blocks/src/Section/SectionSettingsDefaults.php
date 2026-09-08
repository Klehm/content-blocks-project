<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Aggregates the defaults providers: {@see get()} feeds the form on load,
 * {@see withoutDefaults()} strips default-equal entries before render.
 *
 * @see docs/internals/rendering.md#defaults-and-why-they-are-stripped
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
     *
     * @return array<string, mixed>
     */
    public function withoutDefaults(array $settings): array
    {
        return $this->stripDefaults($settings, $this->get());
    }

    /**
     * A nested array left empty by the stripping loses its key too.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $defaults
     *
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
