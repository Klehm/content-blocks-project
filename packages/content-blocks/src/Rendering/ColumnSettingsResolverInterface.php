<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Column;

/**
 * Extension point for what a column renders with — its tab title, today.
 * Autoconfigured; runs in tag priority order over the column's own settings.
 *
 * @see docs/internals/rendering.md#columns-and-tabs
 */
interface ColumnSettingsResolverInterface
{
    /**
     * @param array<string, mixed> $settings so far: the column's own, draft or
     *                                       published by the context's mode
     *
     * @return array<string, mixed>
     */
    public function resolve(Column $column, RenderContext $context, array $settings): array;
}
