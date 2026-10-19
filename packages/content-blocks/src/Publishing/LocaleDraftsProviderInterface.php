<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;

/**
 * The locales holding a draft beside the area. Autoconfigured; registering one
 * lets the builder's Publish and Discard choose which locales they cover.
 *
 * @see docs/internals/publishing.md#choosing-the-locales-from-the-builder
 */
interface LocaleDraftsProviderInterface
{
    /**
     * @return list<LocaleDraft>
     */
    public function pendingLocales(ContentArea $area): array;
}
