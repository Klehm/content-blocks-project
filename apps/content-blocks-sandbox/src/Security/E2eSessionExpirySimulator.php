<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stands in for a host firewall whose session ran out, for the Playwright suite:
 * this sandbox has no security layer, so an expired session cannot happen here.
 *
 * With the `cb_e2e_session=expired` cookie, every admin URL, builder endpoint,
 * Live Component call and draft preview is answered the way a form-login entry
 * point answers it — a redirect, here to the public page list, the "unrelated
 * front page" an editor saw in the reported bug. Priority 8 is the firewall's,
 * so the router has already matched and the package's response listener sees
 * the same request attributes it would behind a real firewall.
 *
 * Guarded on kernel.debug, like TestFixtureController.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class E2eSessionExpirySimulator
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->debug || !$event->isMainRequest()) {
            return;
        }
        if ($request->cookies->get('cb_e2e_session') !== 'expired') {
            return;
        }

        $path = $request->getPathInfo();
        $guarded = str_starts_with($path, '/admin/')
            || str_starts_with($path, '/_components/')
            || $request->query->has('cb_preview');
        if ($guarded) {
            $event->setResponse(new RedirectResponse('/'));
        }
    }
}
