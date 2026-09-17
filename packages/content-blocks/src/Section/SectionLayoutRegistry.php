<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * The section layouts a builder offers: the three built-ins merged with the
 * host's `content_blocks.section.layouts`.
 *
 * @see docs/guide/host-services.md#section-layouts
 */
final class SectionLayoutRegistry
{
    /**
     * @var array<string, array{label: string, columns: list<int>}>
     */
    public const BUILT_IN = [
        Section::LAYOUT_FULL => ['label' => 'cb.section.layout.full', 'columns' => [12]],
        Section::LAYOUT_TWO_COLS => ['label' => 'cb.section.layout.two_cols', 'columns' => [6, 6]],
        Section::LAYOUT_THREE_COLS => ['label' => 'cb.section.layout.three_cols', 'columns' => [4, 4, 4]],
    ];

    /** @var array<string, SectionLayout> */
    private readonly array $layouts;

    /**
     * @param list<array{
     *     name: string,
     *     label: string,
     *     columns: list<int>,
     *     enabled: bool,
     *     display?: string,
     * }>|null $sectionLayouts resolved by {@see self::resolve()}
     */
    public function __construct(?array $sectionLayouts = null)
    {
        $layouts = [];
        foreach ($sectionLayouts ?? self::resolve([]) as $layout) {
            $layouts[$layout['name']] = new SectionLayout(
                $layout['name'],
                $layout['label'],
                $layout['columns'],
                $layout['enabled'],
                $layout['display'] ?? SectionDisplay::GRID,
            );
        }
        $this->layouts = $layouts;
    }

    /**
     * Enabled layouts, in the order the buttons show them.
     *
     * @return list<SectionLayout>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->layouts,
            static fn (SectionLayout $l): bool => $l->enabled && $l->columns !== [],
        ));
    }

    /** A layout a new section may be created with, or null. */
    public function creatable(string $name): ?SectionLayout
    {
        $layout = $this->layouts[$name] ?? null;

        return $layout !== null && $layout->enabled && $layout->columns !== [] ? $layout : null;
    }

    /**
     * Any known layout, disabled ones included: a section created before a
     * layout was hidden still needs its label.
     */
    public function get(string $name): ?SectionLayout
    {
        return $this->layouts[$name] ?? null;
    }

    /**
     * Merges the host's configured layouts over the built-ins. A built-in
     * keeps its place; a new layout is appended in declaration order.
     *
     * @param array<string, array{
     *     enabled?: bool,
     *     label?: string|null,
     *     columns?: list<int>,
     *     display?: string|null,
     * }> $configured
     *
     * @return list<array{
     *     name: string,
     *     label: string,
     *     columns: list<int>,
     *     enabled: bool,
     *     display: string,
     * }>
     */
    public static function resolve(array $configured): array
    {
        $resolved = [];
        foreach (self::BUILT_IN as $name => $layout) {
            $resolved[$name] = ['name' => $name] + $layout + ['enabled' => true, 'display' => SectionDisplay::GRID];
        }

        foreach ($configured as $name => $entry) {
            $name = (string) $name;
            $enabled = $entry['enabled'] ?? true;
            $label = $entry['label'] ?? null;
            $columns = array_values($entry['columns'] ?? []);
            $display = $entry['display'] ?? null;

            if (isset($resolved[$name])) {
                $resolved[$name]['enabled'] = $enabled;
                if ($label !== null && $label !== '') {
                    $resolved[$name]['label'] = $label;
                }
                if ($columns !== []) {
                    $resolved[$name]['columns'] = $columns;
                }
                if ($display !== null) {
                    $resolved[$name]['display'] = $display;
                }
                continue;
            }

            if ($enabled && ($columns === [] || $label === null || $label === '')) {
                $message = 'Section layout "%s" is not a built-in one, so it needs both `label` and `columns`.';
                throw new \InvalidArgumentException(sprintf($message, $name));
            }

            $resolved[$name] = [
                'name' => $name,
                'label' => $label ?? $name,
                'columns' => $columns,
                'enabled' => $enabled,
                'display' => $display ?? SectionDisplay::GRID,
            ];
        }

        return array_values($resolved);
    }
}
