<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Default values for a block's `data` payload. Autoconfigured; merged
 * recursively, so a provider can declare nested keys.
 *
 * @see docs/internals/forms.md#why-defaults-are-merged-on-form-load
 */
interface BlockDataDefaultsProviderInterface
{
    /** @return array<string, mixed> */
    public function getDefaults(): array;
}
