<?php

declare(strict_types=1);

namespace App\Security;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The sandbox's access checker: allows everything, like AllowAllAccessChecker,
 * except the area named by the `cb_e2e_deny_area` cookie, which may be viewed
 * but not edited. Lets the Playwright suite pin what a refused canEdit() does
 * without a security layer this sandbox does not have.
 *
 * Guarded on kernel.debug, like E2eSessionExpirySimulator.
 */
final class E2eAccessChecker implements AccessCheckerInterface
{
    public const COOKIE = 'cb_e2e_deny_area';

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function canEdit(ContentArea $contentArea): bool
    {
        if (!$this->debug) {
            return true;
        }
        $denied = $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE);

        return $denied === null || (string) $contentArea->getId() !== $denied;
    }
}
