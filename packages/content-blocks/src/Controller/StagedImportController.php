<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Transfer\AssetPolicy;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use ContentBlocks\Transfer\ImportResult;
use ContentBlocks\Transfer\ImportSizeLimit;
use ContentBlocks\Transfer\ImportStaging;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * An import in small requests: the plan, then one request per missing file,
 * then the content. No request carries more than one file.
 *
 * @see docs/internals/transfer.md#an-import-in-steps
 *
 * @internal The routes are the contract, not this class. See FREEZE-AUDIT.md.
 */
final class StagedImportController
{
    use CsrfProtectedTrait;

    private const MAX_ASSETS = 10000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaImporterInterface $importer,
        private readonly AssetResolverInterface $assetResolver,
        private readonly AssetPolicy $policy,
        private readonly ImportStaging $staging,
        private readonly BlockTypeRegistry $registry,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ActionJournal $journal,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /**
     * Which listed files this site already holds (same bytes at the listed
     * path, or stored by an earlier attempt) and which must be sent.
     */
    #[Route(
        '/area/{id}/import/plan',
        name: 'content_blocks_import_plan',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function plan(int $id, Request $request): JsonResponse
    {
        $area = $this->editableArea($id, $request);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $body = json_decode($request->getContent(), true);
        $assets = \is_array($body) ? ($body['assets'] ?? []) : null;
        if (!\is_array($assets) || \count($assets) > self::MAX_ASSETS) {
            return new JsonResponse(['error' => 'Invalid "assets" list.'], Response::HTTP_BAD_REQUEST);
        }

        $have = [];
        $need = [];
        foreach ($assets as $hash => $asset) {
            if (!\is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                return new JsonResponse(['error' => 'Invalid asset hash.'], Response::HTTP_BAD_REQUEST);
            }
            $path = \is_array($asset) ? ($asset['path'] ?? null) : null;
            if ($this->staging->storedPath($area, $hash) !== null) {
                $have[] = $hash;
            } elseif (\is_string($path) && $this->holds($path, $hash)) {
                $this->staging->stage($area, $hash, $path);
                $have[] = $hash;
            } else {
                $need[] = $hash;
            }
        }
        $this->staging->expect($area, array_keys($assets));

        $types = \is_array($body['blockTypes'] ?? null) ? $body['blockTypes'] : [];
        $unknown = array_values(array_unique(array_filter(
            $types,
            fn (mixed $type): bool => \is_string($type) && !$this->registry->has($type),
        )));

        return new JsonResponse([
            'have' => $have,
            'need' => $need,
            'unknownBlockTypes' => $unknown,
            'maxAssetBytes' => $this->maxAssetBytes(),
        ]);
    }

    /**
     * One file the plan expects, held to the upload policy. Its hash is
     * computed here: a `hash` field only has to agree with it.
     */
    #[Route(
        '/area/{id}/import/asset',
        name: 'content_blocks_import_asset',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function asset(int $id, Request $request): JsonResponse
    {
        $area = $this->editableArea($id, $request);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $file = $request->files->get('file');
        if ($this->isTooLarge($request, $file)) {
            return $this->refuse('too_large', sprintf(
                'File too large (max %s MB).',
                round($this->maxAssetBytes() / 1024 / 1024, 1),
            ));
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->refuse('upload_failed', 'Missing or invalid file upload.');
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            return $this->refuse('upload_failed', 'Failed to read uploaded file.');
        }

        $hash = hash('sha256', $contents);
        $declared = $request->request->get('hash');
        if (\is_string($declared) && $declared !== '' && !hash_equals($declared, $hash)) {
            return $this->refuse('hash_mismatch', 'The file does not match the export.');
        }
        if (!$this->staging->isExpected($area, $hash)) {
            return $this->refuse('unexpected_asset', 'This file is not part of the import.');
        }

        $path = $this->staging->storedPath($area, $hash);
        if ($path === null) {
            try {
                $extension = $this->policy->check($hash, $contents, $file->getClientOriginalExtension());
            } catch (\InvalidArgumentException $e) {
                return $this->refuse('refused', $e->getMessage());
            }
            $path = $this->assetResolver->store($contents, $extension);
            $this->staging->stage($area, $hash, $path);
        }

        return new JsonResponse(['hash' => $hash, 'path' => $path]);
    }

    /**
     * The content, as one undoable step. Only files this server verified
     * are resolved; the others fall back to their path and are reported.
     */
    #[Route(
        '/area/{id}/import/commit',
        name: 'content_blocks_import_commit',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function commit(int $id, Request $request): JsonResponse
    {
        $area = $this->editableArea($id, $request);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload (expected object).'], Response::HTTP_BAD_REQUEST);
        }

        $stored = $this->staging->stored($area);
        try {
            $result = $this->journal->record($area, 'area.import', JournalScope::structure(), function () use ($area, $payload, $stored): ImportResult {
                $imported = $this->importer->import($area, $payload, $stored);
                $this->em->flush();

                return $imported;
            });
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->staging->clear($area);

        return new JsonResponse([
            'imported' => true,
            'sectionCount' => $result->sectionCount,
            'skippedBlockCount' => $result->skippedBlockCount,
            'skippedBlockTypes' => $result->skippedBlockTypes,
            'unknownFields' => $result->unknownFields,
            'missingAssets' => $result->missingAssets,
            'hasUnpublishedChanges' => $area->hasUnpublishedChanges(),
        ]);
    }

    private function editableArea(int $id, Request $request): ContentArea|JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $area;
    }

    /** The policy's cap, lowered by what PHP accepts in one request. */
    private function maxAssetBytes(): int
    {
        return (new ImportSizeLimit($this->policy->maxSize()))->bytes();
    }

    private function isTooLarge(Request $request, mixed $file): bool
    {
        if ($file instanceof UploadedFile) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true);
        }
        $postMax = ImportSizeLimit::postMaxSize();

        return $postMax !== null && (int) $request->server->get('CONTENT_LENGTH') > $postMax;
    }

    private function holds(string $path, string $hash): bool
    {
        if (!$this->assetResolver->isAssetPath($path)) {
            return false;
        }
        $binary = $this->assetResolver->read($path);

        return $binary !== null && hash_equals($hash, hash('sha256', $binary));
    }

    private function refuse(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'code' => $code], Response::HTTP_BAD_REQUEST);
    }
}
