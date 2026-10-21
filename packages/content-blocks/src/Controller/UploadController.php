<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Storage\FileStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/upload', name: 'content_blocks_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        if ($this->csrfFailureOrNull($request) !== null) {
            return $this->error('csrf', Response::HTTP_FORBIDDEN);
        }

        // Larger than post_max_size: PHP dropped the whole body, the area and
        // the file's name with it. Said as what it is, not as a missing area.
        if ($request->files->count() === 0 && $request->request->count() === 0
            && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            return $this->error('too_large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE, [
                '%max%' => self::megabytes($this->maxSize()),
            ], 'too_large_unnamed');
        }

        $areaId = $request->request->get('area');
        $area = \is_string($areaId) && ctype_digit($areaId)
            ? $this->em->find(ContentArea::class, (int) $areaId)
            : null;
        if ($area === null) {
            return $this->error('area_not_found', Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->error('no_file');
        }

        $name = $file->getClientOriginalName();

        // PHP refused it before the package saw it: say why in our words.
        if (!$file->isValid()) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? $this->error('too_large', params: ['%name%' => $name, '%max%' => self::megabytes($this->maxSize())])
                : $this->error('failed', params: ['%name%' => $name]);
        }

        if ($file->getSize() > $this->uploadMaxSize) {
            return $this->error('too_large', params: ['%name%' => $name, '%max%' => self::megabytes($this->maxSize())]);
        }

        $mimeType = $file->getMimeType();
        if (!\in_array($mimeType, $this->uploadAllowedMimeTypes, true)) {
            return $this->error('type_not_allowed', params: ['%name%' => $name, '%type%' => (string) $mimeType]);
        }

        try {
            $url = $this->fileStorage->upload($file, 'blocks');
        } catch (\Throwable) {
            return $this->error('failed', Response::HTTP_INTERNAL_SERVER_ERROR, ['%name%' => $name]);
        }

        return new JsonResponse(['url' => $url]);
    }

    /** The lower of the package's limit and PHP's, which refuses first. */
    private function maxSize(): int
    {
        $php = (int) UploadedFile::getMaxFilesize();

        return $php > 0 ? min($this->uploadMaxSize, $php) : $this->uploadMaxSize;
    }

    private static function megabytes(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);

        return $mb >= 1 ? (string) round($mb, $mb < 10 ? 1 : 0) : (string) round($mb, 2);
    }

    /**
     * `error` is said to the editor as is; `code` stays the same in every
     * language, for a script to act on.
     *
     * @param array<string, string> $params
     */
    private function error(
        string $code,
        int $status = Response::HTTP_BAD_REQUEST,
        array $params = [],
        ?string $message = null,
    ): JsonResponse {
        $key = 'cb.upload.error.' . ($message ?? $code);
        $message = $this->translator?->trans($key, $params, 'content_blocks') ?? $key;

        return new JsonResponse(['error' => $message, 'message' => $message, 'code' => $code], $status);
    }
}
