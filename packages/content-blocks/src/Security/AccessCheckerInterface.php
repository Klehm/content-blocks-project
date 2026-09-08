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
    public function canEdit(ContentArea $contentArea): bool;

    public function canView(ContentArea $contentArea): bool;
}
