<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Storage\FileStorageInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX upload endpoint backing the `cb-file-upload` Stimulus controller
 * (and any host UI that POSTs a `file` field with the builder's CSRF
 * token).
 *
 * Size cap and MIME allow-list come from the bundle config
 * (`content_blocks.upload.max_size` / `.allowed_mime_types`); the storage
 * backend is whatever FileStorageInterface resolves to — NullFileStorage
 * (throws) until the host opts in via `content_blocks.upload.dir` or its
 * own alias.
 */
#[Route('/_content-blocks')]
final class UploadController
{
    /**
     * @param list<string> $uploadAllowedMimeTypes
     */
    public function __construct(
        private readonly FileStorageInterface $fileStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly int $uploadMaxSize = 10 * 1024 * 1024,
        private readonly array $uploadAllowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
        ],
    ) {
    }

    use CsrfProtectedTrait;

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/upload', name: 'content_blocks_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $file = $request->files->get('file');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            return new JsonResponse(['error' => 'Upload failed: ' . $file->getErrorMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > $this->uploadMaxSize) {
            return new JsonResponse(
                ['error' => sprintf('File too large (max %d MB)', intdiv($this->uploadMaxSize, 1024 * 1024))],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $mimeType = $file->getMimeType();
        if (!\in_array($mimeType, $this->uploadAllowedMimeTypes, true)) {
            return new JsonResponse(['error' => sprintf('File type "%s" is not allowed', $mimeType)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $url = $this->fileStorage->upload($file, 'blocks');
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Upload failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['url' => $url]);
    }
}
