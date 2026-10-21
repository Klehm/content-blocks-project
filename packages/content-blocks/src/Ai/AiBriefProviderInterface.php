<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

use ContentBlocks\Entity\ContentArea;

/**
 * What the host knows about the page an area belongs to (the product, its
 * audience, the tone of voice), handed to every AI request.
 *
 * @see docs/guide/ai.md#a-brief-from-the-host
 */
interface AiBriefProviderInterface
{
    /** Plain text, or null when there is nothing to say. */
    public function briefFor(ContentArea $area): ?string;
}
