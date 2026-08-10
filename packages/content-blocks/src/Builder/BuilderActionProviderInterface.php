<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Contributes entries to the builder topbar's Actions menu.
 *
 * Implementations are autoconfigured (tag `content_blocks.builder_action_provider`)
 * — declare the service and it is picked up.
 *
 * This is the seam for a *bundle*: it adds its action to every builder in the
 * application without the host touching each form. The `topbar_actions` form
 * option remains the seam for a *single form*, and the two are merged. Prefer
 * the option for a one-off, this interface for anything shipped by a package.
 *
 * The area is passed so a provider can decide per-area — returning nothing is
 * how an action hides itself (e.g. when the current user may not run it).
 */
interface BuilderActionProviderInterface
{
    /**
     * @return iterable<BuilderAction>
     */
    public function getActions(ContentArea $area): iterable;
}
