<?php

declare(strict_types=1);

namespace ContentBlocks\Preview;

use ContentBlocks\Entity\ContentArea;

/**
 * Default resolver that always throws, forcing the host to alias
 * {@see ContentAreaUrlResolverInterface} to its own implementation.
 */
final class NullContentAreaUrlResolver implements ContentAreaUrlResolverInterface
{
    public function resolve(ContentArea $area): string
    {
        throw new \LogicException(sprintf('No %s implementation registered. The host app must alias this interface to a concrete service that knows how to map a ContentArea to its public URL.', ContentAreaUrlResolverInterface::class, ));
    }
}
