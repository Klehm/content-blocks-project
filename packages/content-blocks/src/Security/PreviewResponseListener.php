<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use ContentBlocks\Rendering\BlockRendererInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A preview holds the draft: never stored by a shared cache, framed only by
 * its own origin (the builder). Headers the host already set are kept.
 *
 * @see docs/guide/security.md#preview-responses
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class PreviewResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if ($event->getRequest()->query->get(BlockRendererInterface::QUERY_PARAM) !== '1') {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'private, no-store');
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
    }
}
