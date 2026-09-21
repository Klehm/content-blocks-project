<?php

declare(strict_types=1);

namespace ContentBlocks\Icon;

/**
 * Every {@see UiIconProviderInterface} merged by name, the first provider
 * winning: the core set is tagged last, so a host redraws any of its icons.
 */
final class UiIconRegistry
{
    private const SVG_OPEN = '<svg class="cb-ui-icon" viewBox="0 0 20 20" width="20" height="20"'
        . ' fill="none" stroke="currentColor" stroke-width="1.5"'
        . ' stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">';

    /**
     * @param iterable<UiIconProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    public function has(string $name): bool
    {
        return $this->inner($name) !== null;
    }

    /** The whole `<svg>` element, or null for a name no provider knows. */
    public function svg(string $name): ?string
    {
        $inner = $this->inner($name);

        return $inner === null ? null : self::SVG_OPEN . $inner . '</svg>';
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->providers as $provider) {
            $names += array_fill_keys(array_keys($provider->getIcons()), true);
        }

        return array_map('strval', array_keys($names));
    }

    private function inner(string $name): ?string
    {
        foreach ($this->providers as $provider) {
            $icons = $provider->getIcons();
            if (isset($icons[$name])) {
                return $icons[$name];
            }
        }

        return null;
    }
}
