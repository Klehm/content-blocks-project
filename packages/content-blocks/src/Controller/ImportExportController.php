<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Transfer\ContentAreaExporterInterface;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use ContentBlocks\Transfer\ImportResult;
use ContentBlocks\Transfer\ImportSizeLimit;
use ContentBlocks\Transfer\ZipExportWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Exports a ContentArea as a self-contained JSON document, and imports one
 * back with the usual draft-replace semantics.
 *
 * @see docs/internals/transfer.md#import-is-a-replace-and-does-not-flush
 *
 * @internal the routes are the contract, not this class
 */
final class ImportExportController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaExporterInterface $exporter,
        private readonly ContentAreaImporterInterface $importer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ActionJournal $journal,
        private readonly ImportSizeLimit $sizeLimit,
        private readonly ZipExportWriter $zip,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /**
     * A zip streamed as it is written: `content.json`, then the media unless
     * `?assets=0`. Its size is known first, so the browser shows progress.
     *
     * @see docs/internals/transfer.md#the-zip
     */
    #[Route(
        '/area/{id}/export',
        name: 'content_blocks_export',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function export(int $id, Request $request): Response
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canView($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            return new JsonResponse(['error' => 'Cannot open the output.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        [$size, $send] = $this->zip->prepare(
            $this->exporter->export($area),
            $request->query->get('assets') !== '0',
            $output,
        );

        return new StreamedResponse($send, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $size,
            'Content-Disposition' => sprintf(
                'attachment; filename="content-area-%d-%s.zip"',
                $id,
                date('Ymd-His'),
            ),
            'Cache-Control' => 'no-store',
            // nginx would otherwise hold the whole file before relaying it.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** What an export will hold, for the panel to say before downloading. */
    #[Route(
        '/area/{id}/export/summary',
        name: 'content_blocks_export_summary',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function exportSummary(int $id): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canView($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = $this->exporter->export($area);
        $sections = $payload['contentArea']['sections'];
        $blocks = 0;
        foreach ($sections as $section) {
            foreach ($section['columns'] ?? [] as $column) {
                $blocks += \count($column['blocks'] ?? []);
            }
        }
        $sizeOf = function (bool $withMedia) use ($payload): int {
            $sink = fopen('php://memory', 'wb');

            return $sink === false ? 0 : $this->zip->prepare($payload, $withMedia, $sink)[0];
        };

        return new JsonResponse([
            'sectionCount' => \count($sections),
            'blockCount' => $blocks,
            'mediaCount' => \count($payload['assets']),
            'size' => $sizeOf(true),
            'sizeWithoutMedia' => $sizeOf(false),
        ]);
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
        if ($this->isTooLarge($request, $file)) {
            return $this->tooLarge();
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Missing or invalid file upload.'], Response::HTTP_BAD_REQUEST);
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
            $result = $this->journal->record($target, 'area.import', JournalScope::structure(), function () use ($target, $payload): ImportResult {
                $imported = $this->importer->import($target, $payload);
                $this->em->flush();

                return $imported;
            });
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Non-blocking by design (see ImportResult): the import succeeded, and
        // the editor is told what this app could not take in.
        return new JsonResponse([
            'imported' => true,
            'sectionCount' => $result->sectionCount,
            'skippedBlockCount' => $result->skippedBlockCount,
            'skippedBlockTypes' => $result->skippedBlockTypes,
            'unknownFields' => $result->unknownFields,
            'missingAssets' => $result->missingAssets,
            'hasUnpublishedChanges' => $target->hasUnpublishedChanges(),
        ]);
    }

    /**
     * Past `post_max_size` PHP drops the whole body, so the file is simply
     * absent: the declared length is what tells it apart from a missing file.
     */
    private function isTooLarge(Request $request, mixed $file): bool
    {
        if ($file instanceof UploadedFile) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                || ($file->isValid() && $file->getSize() > $this->sizeLimit->bytes());
        }

        $postMax = ImportSizeLimit::postMaxSize();

        return $postMax !== null && (int) $request->server->get('CONTENT_LENGTH') > $postMax;
    }

    private function tooLarge(): JsonResponse
    {
        $max = $this->sizeLimit->bytes();

        return new JsonResponse([
            'error' => sprintf(
                'File too large (max %s MB, set by %s).',
                round($max / 1024 / 1024, 1),
                $this->sizeLimit->source(),
            ),
            'maxBytes' => $max,
        ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }
}
