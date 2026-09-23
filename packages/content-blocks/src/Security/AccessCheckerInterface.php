<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use ContentBlocks\Entity\ContentArea;

/**
 * Authorization for ContentArea editing — **required** from the host, since
 * nothing here knows its auth model. Denied by default.
 *
 * @see docs/guide/host-services.md
 */
interface AccessCheckerInterface
{
    /**
     * Guards every builder action and every read of the unpublished draft.
     * The published page is not checked: the host protects its own route.
     */
    public function canEdit(ContentArea $contentArea): bool;
}
