<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * A column layout offered by the add-section buttons: a name, a label and the
 * span of each column on a 12-unit grid.
 *
 * @see docs/guide/host-services.md#section-layouts
 */
final class SectionLayout
{
    /**
     * @param list<int> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $columns,
        public readonly bool $enabled = true,
        public readonly string $display = SectionDisplay::GRID,
    ) {
    }

    /**
     * The `Column.preset` of each column a new section of this layout gets.
     *
     * @return list<string>
     */
    public function presets(): array
    {
        return array_map(static fn (int $span): string => 'col-' . $span, $this->columns);
    }
}
