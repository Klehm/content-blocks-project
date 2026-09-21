<?php

declare(strict_types=1);

namespace ContentBlocks\Icon;

/**
 * Named icons for the builder's own controls, read by the `cb_icons` option.
 * Autoconfigured; a host provider wins over the core set on a name clash.
 *
 * @see docs/guide/sidebar-fields.md#icon-choices
 */
interface UiIconProviderInterface
{
    /**
     * Inner SVG markup on a 20×20 stroke grid, rendered raw by the registry:
     * never build it from user input.
     *
     * @return array<string, string> name => inner SVG markup
     */
    public function getIcons(): array;
}
