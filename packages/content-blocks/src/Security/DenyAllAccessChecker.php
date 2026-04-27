<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use ContentBlocks\Entity\ContentArea;

/**
 * Default implementation: denies all access.
 * Forces the host application to register its own AccessCheckerInterface.
 */
final class DenyAllAccessChecker implements AccessCheckerInterface
{
    public function canEdit(ContentArea $contentArea): bool
    {
        return false;
    }

    public function canView(ContentArea $contentArea): bool
    {
        return false;
    }
}
