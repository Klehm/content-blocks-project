<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Aggregates {@see SectionStyleProviderInterface} services into a single
 * lookup. Wired with a `tagged_iterator` so all providers — host-app +
 * package-internal — surface together.
 */
final class SectionStyleRegistry
{
    /**
     * @param iterable<SectionStyleProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    /**
     * @return array<string, SectionStyle>  Indexed by style name.
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getStyles() as $style) {
                $out[$style->name] = $style;
            }
        }

        return $out;
    }

    public function get(string $name): ?SectionStyle
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Form-friendly choices: label => name.
     *
     * @return array<string, string>
     */
    public function getChoices(): array
    {
        $out = [];
        foreach ($this->all() as $style) {
            $out[$style->label] = $style->name;
        }

        return $out;
    }
}
