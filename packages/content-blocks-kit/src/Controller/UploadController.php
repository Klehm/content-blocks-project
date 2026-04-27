<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Controller;

use ContentBlocks\Kit\Storage\FileStorageInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/_content-blocks')]
final class UploadController
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
    ];

    public function __construct(
        private readonly FileStorageInterface $fileStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/upload', name: 'content_blocks_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('content_blocks', $token))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            return new JsonResponse(['error' => 'Upload failed: ' . $file->getErrorMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return new JsonResponse(['error' => 'File too large (max 10 MB)'], Response::HTTP_BAD_REQUEST);
        }

        $mimeType = $file->getMimeType();
        if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return new JsonResponse(['error' => sprintf('File type "%s" is not allowed', $mimeType)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $url = $this->fileStorage->upload($file, 'blocks');
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Upload failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['url' => $url]);
    }
}
