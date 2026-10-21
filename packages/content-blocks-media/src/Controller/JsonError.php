<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The package's error answer: `error` is a code a script can act on,
 * `message` is what the picker says to the editor, in their language.
 *
 * @internal
 */
final class JsonError
{
    public static function response(?TranslatorInterface $translator, string $code, int $status): JsonResponse
    {
        $key = 'cb_media.error.' . $code;
        $message = $translator?->trans($key, [], 'content_blocks_media') ?? $key;

        return new JsonResponse(['error' => $code, 'message' => $message], $status);
    }
}
