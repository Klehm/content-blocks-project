<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;

/**
 * Every provider's pending locales, merged by code. With no provider, the
 * builder's Publish and Discard ask nothing and cover everything.
 *
 * @see docs/internals/publishing.md#choosing-the-locales-from-the-builder
 */
final class LocaleDrafts
{
    /**
     * @param iterable<LocaleDraftsProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    public function isEnabled(): bool
    {
        foreach ($this->providers as $provider) {
            return true;
        }

        return false;
    }

    /**
     * @return list<LocaleDraft>
     */
    public function of(ContentArea $area): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->pendingLocales($area) as $draft) {
                $out[$draft->code] ??= $draft;
            }
        }

        return array_values($out);
    }
}
