<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

/**
 * A locale holding a draft Publish would put live, as the builder's Publish
 * and Discard dialog lists it.
 */
final class LocaleDraft
{
    public function __construct(
        /** The code `PublishContext::withLocales()` takes, e.g. `en`. */
        public readonly string $code,
        /** What the editor reads, e.g. `English`. */
        public readonly string $label,
    ) {
    }
}
