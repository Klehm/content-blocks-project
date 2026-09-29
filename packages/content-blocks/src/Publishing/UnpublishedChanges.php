<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;

/**
 * Whether Publish has anything to do: the area's own draft, or one a bundle
 * keeps beside it. What the builder's Publish and Discard read.
 *
 * @see docs/internals/publishing.md#drafts-kept-beside-the-area
 */
final class UnpublishedChanges
{
    /**
     * @param iterable<UnpublishedChangesProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    public function of(ContentArea $area): bool
    {
        if ($area->hasUnpublishedChanges()) {
            return true;
        }

        foreach ($this->providers as $provider) {
            if ($provider->hasUnpublishedChanges($area)) {
                return true;
            }
        }

        return false;
    }
}
