<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns a login redirect answering a builder fetch into a 401 it can read.
 * Runs before Live's listener, which would have the redirect leave the page.
 *
 * @see docs/internals/frontend.md#an-expired-session-is-said-not-followed
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 10)]
final class SessionExpiredResponseListener
{
    public const HEADER = 'X-Content-Blocks-Session';

    private const LIVE_ACCEPT = 'application/vnd.live-component+html';

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response->isRedirection() || !$response->headers->has('Location')) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isBuilderFetch($request)) {
            return;
        }

        $expired = new JsonResponse(
            ['error' => 'session_expired', 'login' => $response->headers->get('Location')],
            Response::HTTP_UNAUTHORIZED,
        );
        $expired->headers->set(self::HEADER, 'expired');
        $event->setResponse($expired);
    }

    private function isBuilderFetch(Request $request): bool
    {
        if (!$this->isPackageRoute($request)) {
            return false;
        }

        // A navigation (the workbench page, the asset report) must still
        // reach the login form.
        $mode = $request->headers->get('Sec-Fetch-Mode');
        if ($mode !== null) {
            return $mode !== 'navigate';
        }

        $accept = (string) $request->headers->get('Accept', '');

        return $request->isXmlHttpRequest()
            || str_contains($accept, 'application/json')
            || str_contains($accept, self::LIVE_ACCEPT);
    }

    private function isPackageRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (\is_string($route) && str_starts_with($route, 'content_blocks_')) {
            return true;
        }

        $component = $request->attributes->get('_live_component');

        return \is_string($component) && str_starts_with($component, 'ContentBlocks:');
    }
}
