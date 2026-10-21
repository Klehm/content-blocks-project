<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Security;

use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Whether the visitor is a logged-in shop administrator, on any firewall: the
 * preview and the Live routes run on the shop one, the admin is elsewhere.
 *
 * @see docs/internals/sylius.md#who-may-edit
 */
final class AdminVisitor
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly string $adminFirewallContext = 'admin',
        private readonly string $role = 'ROLE_ADMINISTRATION_ACCESS',
    ) {
    }

    public function isAdmin(): bool
    {
        if (self::grants($this->security->getToken(), $this->role)) {
            return true;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return false;
        }

        $serialized = $request->getSession()->get('_security_' . $this->adminFirewallContext);
        if (!\is_string($serialized) || $serialized === '') {
            return false;
        }

        $token = @unserialize($serialized);

        return $token instanceof TokenInterface && self::grants($token, $this->role);
    }

    private static function grants(?TokenInterface $token, string $role): bool
    {
        if ($token === null || !$token->getUser() instanceof AdminUserInterface) {
            return false;
        }

        return \in_array($role, $token->getRoleNames(), true);
    }
}
