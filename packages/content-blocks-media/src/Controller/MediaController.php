<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use ContentBlocks\Media\Library\MediaBrowser;
use ContentBlocks\Media\Library\MediaKind;
use ContentBlocks\Media\Library\MediaView;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The picker's API. Access is the edit right on the area the picker was
 * opened from, the same check as the core's upload endpoint.
 *
 * @see docs/internals/media.md#who-may-browse
 */
final class MediaController
{
    private const CSRF_TOKEN_ID = 'content_blocks';
    // How long a just-uploaded file may be filed by an editor who cannot manage.
    private const FRESH_UPLOAD = '-1 hour';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly MediaBrowser $browser,
        private readonly ImageUrlResolverInterface $images,
        private readonly MediaManagerInterface $manager,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    #[Route('/list', name: 'content_blocks_media_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $area = $this->area($request->query->get('area'));
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $kind = (string) $request->query->get('kind', MediaKind::IMAGE);
        if (!\in_array($kind, [MediaKind::IMAGE, MediaKind::VIDEO], true)) {
            return $this->error('unknown_kind', Response::HTTP_BAD_REQUEST);
        }

        $folder = $this->folder($request->query->get('folder'));
        if ($folder instanceof JsonResponse) {
            return $folder;
        }

        return new JsonResponse($this->browser->browse(
            $kind,
            $folder,
            (string) $request->query->get('q', ''),
            (int) $request->query->get('page', '1'),
        ));
    }

    /**
     * Files a just-uploaded file under the folder the editor is in. Moving any
     * other file is managing the library.
     */
    #[Route('/place', name: 'content_blocks_media_place', methods: ['POST'])]
    public function place(Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', '')))) {
            return $this->error('csrf', Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->error('invalid_body', Response::HTTP_BAD_REQUEST);
        }

        $area = $this->area($body['area'] ?? null);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $path = $body['path'] ?? null;
        $media = \is_string($path) && $path !== ''
            ? $this->em->getRepository(Media::class)->findOneBy(['path' => $path])
            : null;
        if ($media === null) {
            return $this->error('not_in_library', Response::HTTP_NOT_FOUND);
        }

        $folder = $this->folder($body['folder'] ?? null);
        if ($folder instanceof JsonResponse) {
            return $folder;
        }

        if (!$this->manager->canManage() && !self::justUploaded($media)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $media->moveTo($folder);
        $this->em->flush();

        return new JsonResponse(MediaView::media($media, $this->images));
    }

    private static function justUploaded(Media $media): bool
    {
        return $media->getFolder() === null
            && $media->getCreatedAt() >= new \DateTimeImmutable(self::FRESH_UPLOAD);
    }

    private function area(mixed $id): ContentArea|JsonResponse
    {
        $area = \is_scalar($id) && ctype_digit((string) $id)
            ? $this->em->find(ContentArea::class, (int) $id)
            : null;
        if ($area === null) {
            return $this->error('area_not_found', Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $area;
    }

    /** Null or empty is the root. */
    private function folder(mixed $id): MediaFolder|JsonResponse|null
    {
        if ($id === null || $id === '') {
            return null;
        }
        $folder = \is_scalar($id) && ctype_digit((string) $id)
            ? $this->em->find(MediaFolder::class, (int) $id)
            : null;

        return $folder ?? $this->error('folder_not_found', Response::HTTP_NOT_FOUND);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return JsonError::response($this->translator, $code, $status);
    }
}
