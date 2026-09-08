<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Aggregates the defaults providers into one map: {@see get()} fills a form,
 * {@see withoutDefaults()} strips it again before rendering.
 *
 * @see docs/internals/forms.md#why-defaults-are-merged-on-form-load
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
     *
     * @return array<string, mixed>
     */
    public function withoutDefaults(array $data): array
    {
        return $this->stripDefaults($data, $this->get());
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     *
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
