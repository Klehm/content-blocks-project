<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Security;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;

/**
 * A shop administrator edits every area: Sylius has no per-resource rights.
 * A host with finer rules aliases its own checker.
 */
final class AdminAccessChecker implements AccessCheckerInterface
{
    public function __construct(private readonly AdminVisitor $visitor)
    {
    }

    public function canEdit(ContentArea $contentArea): bool
    {
        return $this->visitor->isAdmin();
    }
}
