<?php

declare(strict_types=1);

namespace App\Controller;

use Intervention\Image\Exceptions\DecoderException;
use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Server;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the variants `GlideImageUrlResolver` links to. Only signed URLs are
 * answered: an open `?w=` would let anyone fill the cache directory.
 *
 * @see docs/guide/recipes/glide.md
 */
final class GlideImageController
{
    public function __construct(
        private readonly Server $server,
        private readonly SignatureInterface $signature,
    ) {
    }

    #[Route('/img/{path}', name: 'app_glide_image', requirements: ['path' => '.+'], methods: ['GET'])]
    public function __invoke(string $path, Request $request): Response
    {
        $params = $request->query->all();

        try {
            $this->signature->validateRequest($path, $params);
        } catch (SignatureException) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        try {
            $cached = $this->server->makeImage($path, $params);
        } catch (FileNotFoundException) {
            return new Response('', Response::HTTP_NOT_FOUND);
        } catch (DecoderException) {
            return new Response('', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $cache = $this->server->getCache();

        return new StreamedResponse(static function () use ($cache, $cached): void {
            fpassthru($cache->readStream($cached));
        }, Response::HTTP_OK, [
            'Content-Type' => $cache->mimeType($cached),
            'Content-Length' => (string) $cache->fileSize($cached),
            // The signature pins the parameters, so a URL's bytes never change.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
