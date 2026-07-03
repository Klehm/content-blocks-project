<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * Section style presets fed by the bundle's semantic config
 * (`content_blocks.styles`). Registered unconditionally; an empty config
 * simply contributes no styles.
 */
final class ConfigSectionStyleProvider implements SectionStyleProviderInterface
{
    /**
     * @param list<array{name: string, label: string, css_class?: string, settings?: array<string, mixed>}> $styles
     */
    public function __construct(
        private readonly array $styles = [],
    ) {
    }

    public function getStyles(): array
    {
        return array_map(
            static fn (array $style): SectionStyle => new SectionStyle(
                $style['name'],
                $style['label'],
                $style['css_class'] ?? '',
                $style['settings'] ?? [],
            ),
            $this->styles,
        );
    }
}
