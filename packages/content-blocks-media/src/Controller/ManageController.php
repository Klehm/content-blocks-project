<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use ContentBlocks\Media\Library\MediaUsage;
use ContentBlocks\Media\Library\MediaView;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Replace\ContentAreaProviderInterface;
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
 * Managing the library: folders, metadata, moves, removal. Every write needs
 * MediaManagerInterface; removing a file deletes its row, never the file.
 *
 * @see docs/internals/media.md#managing-the-library
 */
final class ManageController
{
    private const CSRF_TOKEN_ID = 'content_blocks';
    private const MAX_LENGTH = 255;
    // A page of the picker holds 40; a select-all over several pages, more.
    private const MAX_FILES = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly MediaManagerInterface $manager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ImageUrlResolverInterface $images,
        private readonly MediaUsage $usage,
        private readonly ContentAreaProviderInterface $areaProvider,
        private readonly ContentAreaUrlResolverInterface $areaUrls,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /** Every folder, depth first: what the move menu lists. */
    #[Route('/folders', name: 'content_blocks_media_folders', methods: ['GET'])]
    public function folders(Request $request): JsonResponse
    {
        $area = $this->area($request->query->get('area'));
        if ($area instanceof JsonResponse) {
            return $area;
        }

        /** @var list<MediaFolder> $all */
        $all = $this->em->getRepository(MediaFolder::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);

        return new JsonResponse(['folders' => self::flatten($all)]);
    }

    #[Route('/folder/create', name: 'content_blocks_media_folder_create', methods: ['POST'])]
    public function createFolder(Request $request): JsonResponse
    {
        $body = $this->managed($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $name = self::text($body['name'] ?? null, required: true);
        if ($name === null) {
            return $this->error('invalid_name');
        }
        $parent = $this->find(MediaFolder::class, $body['parent'] ?? null);
        if ($parent === false) {
            return $this->error('folder_not_found', Response::HTTP_NOT_FOUND);
        }

        $siblings = $this->em->getRepository(MediaFolder::class)->findBy(['parent' => $parent]);
        $position = array_reduce($siblings, static fn (int $max, MediaFolder $f) => max($max, $f->getPosition() + 1), 0);
        $folder = new MediaFolder($name, $parent);
        $folder->moveTo($parent, $position);
        $this->em->persist($folder);
        $this->em->flush();

        return new JsonResponse(MediaView::folder($folder));
    }

    #[Route('/folder/rename', name: 'content_blocks_media_folder_rename', methods: ['POST'])]
    public function renameFolder(Request $request): JsonResponse
    {
        $body = $this->managed($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $folder = $this->find(MediaFolder::class, $body['id'] ?? null);
        if (!$folder instanceof MediaFolder) {
            return $this->error('folder_not_found', Response::HTTP_NOT_FOUND);
        }
        $name = self::text($body['name'] ?? null, required: true);
        if ($name === null) {
            return $this->error('invalid_name');
        }

        $folder->rename($name);
        $this->em->flush();

        return new JsonResponse(MediaView::folder($folder));
    }

    /** Its subfolders go with it; their files move to the root, never lost. */
    #[Route('/folder/delete', name: 'content_blocks_media_folder_delete', methods: ['POST'])]
    public function deleteFolder(Request $request): JsonResponse
    {
        $body = $this->managed($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $folder = $this->find(MediaFolder::class, $body['id'] ?? null);
        if (!$folder instanceof MediaFolder) {
            return $this->error('folder_not_found', Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($folder);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
    }

    #[Route('/file/update', name: 'content_blocks_media_file_update', methods: ['POST'])]
    public function updateFile(Request $request): JsonResponse
    {
        $body = $this->managed($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $media = $this->find(Media::class, $body['id'] ?? null);
        if (!$media instanceof Media) {
            return $this->error('not_in_library', Response::HTTP_NOT_FOUND);
        }

        if (\array_key_exists('title', $body)) {
            $title = self::text($body['title'], required: true);
            if ($title === null) {
                return $this->error('invalid_title');
            }
            $media->setTitle($title);
        }
        if (\array_key_exists('alt', $body)) {
            $alt = self::text($body['alt'], required: false);
            if ($alt === null) {
                return $this->error('invalid_alt');
            }
            $media->setAlt($alt);
        }
        $this->em->flush();

        return new JsonResponse(MediaView::media($media, $this->images));
    }

    /** One or several files, in one request: what a drag onto a folder sends. */
    #[Route('/file/move', name: 'content_blocks_media_file_move', methods: ['POST'])]
    public function moveFile(Request $request): JsonResponse
    {
        $body = $this->managed($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $files = $this->files($body['ids'] ?? null);
        if ($files instanceof JsonResponse) {
            return $files;
        }
        $folder = $this->find(MediaFolder::class, $body['folder'] ?? null);
        if ($folder === false) {
            return $this->error('folder_not_found', Response::HTTP_NOT_FOUND);
        }

        foreach ($files as $media) {
            $media->moveTo($folder);
        }
        $this->em->flush();

        return new JsonResponse(['moved' => \count($files)]);
    }

    /** The rows go, the files stay: the asset GC decides, as for any edit. */
    #[Route('/file/remove', name: 'content_blocks_media_file_remove', methods: ['POST'])]
    public function removeFile(Request $request): JsonResponse
    {
        $body = $this->managed($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $files = $this->files($body['ids'] ?? null);
        if ($files instanceof JsonResponse) {
            return $files;
        }

        foreach ($files as $media) {
            $this->em->remove($media);
        }
        $this->em->flush();

        return new JsonResponse(['removed' => \count($files)]);
    }

    /**
     * Pages the editor may edit are named; the others are only counted, so the
     * library leaks no title of a page its editor cannot open.
     */
    #[Route('/file/usage', name: 'content_blocks_media_file_usage', methods: ['GET'])]
    public function usage(Request $request): JsonResponse
    {
        $area = $this->area($request->query->get('area'));
        if ($area instanceof JsonResponse) {
            return $area;
        }
        $media = $this->find(Media::class, $request->query->get('id'));
        if (!$media instanceof Media) {
            return $this->error('not_in_library', Response::HTTP_NOT_FOUND);
        }

        $pages = [];
        $hidden = 0;
        foreach ($this->usage->areasUsing($media->getPath()) as $areaId => $where) {
            $using = $this->em->find(ContentArea::class, $areaId);
            if ($using === null || !$this->accessChecker->canEdit($using)) {
                ++$hidden;

                continue;
            }
            $pages[] = [
                'area' => $areaId,
                'label' => $this->areaProvider->getLabel($using),
                'url' => $this->publicUrl($using),
            ] + $where;
        }

        return new JsonResponse(['pages' => $pages, 'others' => $hidden]);
    }

    private function publicUrl(ContentArea $area): ?string
    {
        try {
            return $this->areaUrls->resolve($area);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Folders under their parent, each with its depth.
     *
     * @param list<MediaFolder> $all
     *
     * @return list<array{id: int|null, name: string, depth: int}>
     */
    public static function flatten(array $all): array
    {
        $children = [];
        foreach ($all as $folder) {
            $children[$folder->getParent()?->getId() ?? 0][] = $folder;
        }

        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $children): void {
            // Bounded like the breadcrumb: a parent cycle must not recurse.
            if ($depth > 64) {
                return;
            }
            foreach ($children[$parent] ?? [] as $folder) {
                $out[] = MediaView::folder($folder) + ['depth' => $depth];
                $walk((int) $folder->getId(), $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /**
     * Every id must name a library file, or nothing changes.
     *
     * @return list<Media>|JsonResponse
     */
    private function files(mixed $ids): array|JsonResponse
    {
        if (!\is_array($ids) || $ids === [] || \count($ids) > self::MAX_FILES) {
            return $this->error('invalid_ids');
        }

        $files = [];
        foreach (array_unique($ids, \SORT_REGULAR) as $id) {
            $media = $this->find(Media::class, $id);
            if (!$media instanceof Media) {
                return $this->error('not_in_library', Response::HTTP_NOT_FOUND);
            }
            $files[] = $media;
        }

        return $files;
    }

    /** @return array<string, mixed>|JsonResponse */
    private function managed(Request $request): array|JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', '')))) {
            return $this->error('csrf', Response::HTTP_FORBIDDEN);
        }
        if (!$this->manager->canManage()) {
            throw new ContentBlocksAccessDeniedException();
        }
        $body = json_decode($request->getContent(), true);

        return \is_array($body) ? $body : $this->error('invalid_body');
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

    /**
     * Null for an empty id (the root), false for an id that finds nothing.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|false|null
     */
    private function find(string $class, mixed $id): object|false|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        return (\is_scalar($id) && ctype_digit((string) $id) ? $this->em->find($class, (int) $id) : null) ?? false;
    }

    /** Trimmed, at most 255 characters; null when refused. */
    private static function text(mixed $value, bool $required): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return $value;
    }

    private function error(string $error, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return JsonError::response($this->translator, $error, $status);
    }
}
