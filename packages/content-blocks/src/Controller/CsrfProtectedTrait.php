<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Shared CSRF check for the AJAX builder endpoints. The token is rendered
 * in the shell template as `data-cb-csrf-token` and forwarded by the
 * cb-builder Stimulus controller in the X-CSRF-Token header.
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
