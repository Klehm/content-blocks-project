<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Event\RefusableEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `409` a refused before event answers, the shape `cb-builder` reads.
 *
 * @see docs/guide/events.md#refusing-an-action
 *
 * @internal
 */
final class EventRefusal
{
    public static function response(RefusableEvent $event): JsonResponse
    {
        return new JsonResponse([
            'error' => 'refused',
            'message' => implode(' ', $event->getReasons()),
            'reasons' => $event->getReasons(),
        ], Response::HTTP_CONFLICT);
    }
}
