<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Shared CSRF check for the AJAX builder endpoints. The shell renders the
 * token as `data-cb-csrf-token`; cb-builder sends it as `X-CSRF-Token`.
 *
 * @internal the routes are the contract, not this trait
 */
trait CsrfProtectedTrait
{
    private const CSRF_TOKEN_ID = 'content_blocks';

    abstract private function getCsrfTokenManager(): CsrfTokenManagerInterface;

    private function csrfFailureOrNull(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token', '');

        if (!$this->getCsrfTokenManager()->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
