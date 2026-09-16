<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Workbench;

use ContentBlocks\Entity\ContentArea;

/**
 * Where the workbench's back arrow leads. Alias it to send translators back to
 * the host's admin rather than to the public page, the default.
 *
 * @see docs/guide/translation.md#the-back-arrow
 */
interface WorkbenchBackUrlResolverInterface
{
    /**
     * @param string $locale the target locale the workbench is open on
     */
    public function resolve(ContentArea $area, string $locale): string;
}
