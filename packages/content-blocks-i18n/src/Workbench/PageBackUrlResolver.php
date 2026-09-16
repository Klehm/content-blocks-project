<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Workbench;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;

/**
 * Default back URL: the page itself, as the host's preview resolver names it.
 */
final class PageBackUrlResolver implements WorkbenchBackUrlResolverInterface
{
    public function __construct(
        private readonly ContentAreaUrlResolverInterface $urlResolver,
    ) {
    }

    public function resolve(ContentArea $area, string $locale): string
    {
        return $this->urlResolver->resolve($area);
    }
}
