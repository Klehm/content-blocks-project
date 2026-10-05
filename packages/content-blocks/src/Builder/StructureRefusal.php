<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer of an endpoint whose structural change the area's
 * {@see BuilderStructure} rules out. Same shape as an event refusal.
 *
 * @internal
 */
final class StructureRefusal
{
    public const REASON = 'structure';

    public static function response(): JsonResponse
    {
        return new JsonResponse(['error' => 'refused', 'reasons' => [self::REASON]], Response::HTTP_CONFLICT);
    }
}
