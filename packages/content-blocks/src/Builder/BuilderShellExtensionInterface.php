<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Contributes markup to the builder shell — the UI half a bundle needs, since
 * unlike a host it owns no page to hang a listener on. Autoconfigured.
 *
 * @see docs/internals/builder-extensions.md#two-halves-of-one-seam
 */
interface BuilderShellExtensionInterface
{
    /**
     * @return iterable<BuilderShellFragment>
     */
    public function getFragments(ContentArea $area): iterable;
}
