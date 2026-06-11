<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Service\ContentAreaExporter;
use ContentBlocks\Service\ContentAreaImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Endpoints for exporting / importing a ContentArea as a self-contained
 * JSON document (sections + columns + blocks + base64-encoded assets).
 *
 *  - GET  /area/{id}/export   Streams a JSON download
 *  - POST /area/{id}/import   Replaces the draft with the uploaded JSON
 *
 * Import follows the same "draft replace" semantics as ReplaceController:
 * existing sections are soft-deleted, imported sections are added as
 * never-published drafts. Publish commits the swap, Discard reverts it.
 */
#[Route('/_content-blocks')]
final class ImportExportController
{
    use CsrfProtectedTrait;

    /** Hard cap on uploaded JSON size (base64 inflates binary by ~33%). */
    private const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaExporter $exporter,
        private readonly ContentAreaImporter $importer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route(
        '/area/{id}/export',
        name: 'content_blocks_export',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function export(int $id): Response
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canView($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = $this->exporter->export($area);
        $json = json_encode(
            $payload,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            return new JsonResponse(
                ['error' => 'Failed to encode export: ' . json_last_error_msg()],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $filename = sprintf('content-area-%d-%s.json', $id, date('Ymd-His'));
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $filename),
        );

        return $response;
    }

    #[Route(
        '/area/{id}/import',
        name: 'content_blocks_import',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function import(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $target = $this->em->find(ContentArea::class, $id);
        if (!$target) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($target)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Missing or invalid file upload.'], Response::HTTP_BAD_REQUEST);
        }
        if ($file->getSize() !== false && $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return new JsonResponse(
                ['error' => sprintf('File too large (max %d MB).', (int) (self::MAX_UPLOAD_BYTES / 1024 / 1024))],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            return new JsonResponse(['error' => 'Failed to read uploaded file.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(
                ['error' => 'Invalid JSON: ' . $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload (expected object).'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $count = $this->importer->import($target, $payload);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return new JsonResponse([
            'imported' => true,
            'sectionCount' => $count,
            'hasUnpublishedChanges' => $target->hasUnpublishedChanges(),
        ]);
    }
}
