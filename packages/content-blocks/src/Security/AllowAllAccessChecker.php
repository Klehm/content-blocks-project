<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use ContentBlocks\Entity\ContentArea;

/**
 * Allows all access. Use ONLY for development/sandbox environments.
 */
final class AllowAllAccessChecker implements AccessCheckerInterface
{
    public function canEdit(ContentArea $contentArea): bool
    {
        return true;
    }

    public function canView(ContentArea $contentArea): bool
    {
        return true;
    }
}
