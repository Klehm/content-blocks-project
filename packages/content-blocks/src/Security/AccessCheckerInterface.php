<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use ContentBlocks\Entity\ContentArea;

/**
 * Implement this interface in your application to control access to ContentArea editing.
 *
 * ContentBlocks does not know your authentication model. Your app must provide
 * an implementation and register it as a service. Without one, the default
 * DenyAllAccessChecker rejects every mutation.
 */
interface AccessCheckerInterface
{
    public function canEdit(ContentArea $contentArea): bool;

    public function canView(ContentArea $contentArea): bool;
}
