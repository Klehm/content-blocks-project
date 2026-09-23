<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Storage\FileStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX upload endpoint behind `cb-file-upload`, for an editor of the `area`
 * posted with the file. Limits come from `content_blocks.upload.*`.
 *
 * @see docs/guide/security.md#file-upload
 *
 * @internal The routes are the contract, not this class. See FREEZE-AUDIT.md.
 */
final class UploadController
{
    use CsrfProtectedTrait;
    /**
     * @param list<string> $uploadAllowedMimeTypes
     */
    public function __construct(
        private readonly FileStorageInterface $fileStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly int $uploadMaxSize = 10 * 1024 * 1024,
        private readonly array $uploadAllowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
        ],
    ) {
    }

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

        $areaId = $request->request->get('area');
        $area = \is_string($areaId) && ctype_digit($areaId)
            ? $this->em->find(ContentArea::class, (int) $areaId)
            : null;
        if ($area === null) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
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
