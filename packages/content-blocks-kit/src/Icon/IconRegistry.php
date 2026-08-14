<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Icon;

/**
 * The icons available at runtime: the kit's shipped {@see IconSet} plus
 * whatever {@see IconProviderInterface} services the host registered.
 *
 * One resolved set feeds both ends — the `icon` block's picker and
 * `cb_kit_icon()` — so a name that can be chosen is always a name that can be
 * drawn. `IconSet`'s static API is untouched and still describes the shipped
 * glyphs; this is what anything runtime should read.
 */
final class IconRegistry
{
    /** @var array<string, string>|null name => inner SVG markup, resolved once */
    private ?array $icons = null;

    /**
     * @param iterable<IconProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /**
     * @return array<string, string> name => inner SVG markup
     */
    public function all(): array
    {
        if ($this->icons !== null) {
            return $this->icons;
        }

        $icons = IconSet::all();
        foreach ($this->providers as $provider) {
            // Providers are merged over the shipped set, so naming a kit icon
            // replaces its glyph rather than being ignored — the obvious way
            // to restyle one without overriding the block's template.
            foreach ($provider->icons() as $name => $inner) {
                if (\is_string($name) && \is_string($inner) && $name !== '') {
                    $icons[$name] = $inner;
                }
            }
        }

        return $this->icons = $icons;
    }

    /**
     * Form-friendly choices: label (Title Case) => name, matching what
     * {@see IconSet::choices()} produces for the shipped glyphs.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $out = [];
        foreach (array_keys($this->all()) as $name) {
            $out[ucwords(str_replace('-', ' ', $name))] = $name;
        }

        return $out;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** Inner SVG markup for a name, or null if no provider supplies it. */
    public function inner(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Full `<svg>` markup for a name, or null when unknown. Stroke style with
     * `currentColor`, sized via width/height attributes — the wrapper is the
     * kit's so every glyph, shipped or contributed, looks like one family.
     */
    public function svg(string $name, int $size = 24): ?string
    {
        $inner = $this->inner($name);
        if ($inner === null) {
            return null;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" '
            . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
            . 'stroke-linejoin="round">%2$s</svg>',
            $size,
            $inner,
        );
    }
}
