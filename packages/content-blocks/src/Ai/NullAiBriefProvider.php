<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

use ContentBlocks\Entity\ContentArea;

/**
 * Default {@see AiBriefProviderInterface}: the area alone says nothing about
 * its owner, so there is no brief.
 */
final class NullAiBriefProvider implements AiBriefProviderInterface
{
    public function briefFor(ContentArea $area): ?string
    {
        return null;
    }
}
