<?php

declare(strict_types=1);

namespace ContentBlocks\Preview;

use ContentBlocks\Entity\ContentArea;

/**
 * The public URL of whatever owns a ContentArea — **required** from the host,
 * since nothing here can derive it. Return it clean, with no query parameter.
 *
 * @see docs/guide/host-services.md
 */
interface ContentAreaUrlResolverInterface
{
    public function resolve(ContentArea $area): string;
}
