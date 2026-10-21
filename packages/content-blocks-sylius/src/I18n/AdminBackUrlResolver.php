<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\I18n;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Workbench\WorkbenchBackUrlResolverInterface;
use ContentBlocks\Sylius\Routing\AdminUrls;

/** The workbench's back arrow leads to the admin screen editing the area. */
final class AdminBackUrlResolver implements WorkbenchBackUrlResolverInterface
{
    public function __construct(private readonly AdminUrls $urls)
    {
    }

    public function resolve(ContentArea $area, string $locale): string
    {
        return $this->urls->edit($area);
    }
}
