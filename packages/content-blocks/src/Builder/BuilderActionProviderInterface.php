<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Contributes entries to the builder topbar's Actions menu — the seam for a
 * bundle, where `topbar_actions` is the one for a single form. Autoconfigured.
 *
 * @see docs/internals/builder-extensions.md#two-halves-of-one-seam
 */
interface BuilderActionProviderInterface
{
    /**
     * @return iterable<BuilderAction>
     */
    public function getActions(ContentArea $area): iterable;
}
