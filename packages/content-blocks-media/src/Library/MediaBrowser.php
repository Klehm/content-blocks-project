<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Library;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One page of the library as the picker shows it: a folder's subfolders and
 * files, or a search across every folder. Newest first.
 */
final class MediaBrowser
{
    public const PAGE_SIZE = 40;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageUrlResolverInterface $images,
    ) {
    }

    /**
     * @param list<string> $kinds every kind listed, the library at once
     *
     * @return array{
     *     folder: array{id: int|null, name: string}|null,
     *     breadcrumb: list<array{id: int|null, name: string}>,
     *     folders: list<array{id: int|null, name: string}>,
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     hasMore: bool,
     * }
     */
    public function browse(array $kinds, ?MediaFolder $folder, string $query, int $page): array
    {
        $query = trim($query);
        $page = max(1, $page);

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.kind IN (:kinds)')
            ->setParameter('kinds', $kinds)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            // One more than a page: the sentinel that says there is more.
            ->setMaxResults(self::PAGE_SIZE + 1);

        if ($query !== '') {
            $qb->andWhere('LOWER(m.title) LIKE :q OR LOWER(m.path) LIKE :q OR LOWER(m.alt) LIKE :q')
                ->setParameter('q', self::likePattern($query));
        } elseif ($folder === null) {
            $qb->andWhere('m.folder IS NULL');
        } else {
            $qb->andWhere('m.folder = :folder')->setParameter('folder', $folder);
        }

        /** @var list<Media> $rows */
        $rows = $qb->getQuery()->getResult();
        $hasMore = \count($rows) > self::PAGE_SIZE;

        return [
            'folder' => $folder === null ? null : MediaView::folder($folder),
            'breadcrumb' => $query === '' ? self::breadcrumb($folder) : [],
            'folders' => $query === '' ? $this->subfolders($folder) : [],
            'items' => array_map(
                fn (Media $media): array => MediaView::media($media, $this->images),
                \array_slice($rows, 0, self::PAGE_SIZE),
            ),
            'page' => $page,
            'hasMore' => $hasMore,
        ];
    }

    /** Case-insensitive, with `%` and `_` taken literally. */
    public static function likePattern(string $query): string
    {
        return '%' . addcslashes(mb_strtolower($query), '%_\\') . '%';
    }

    /**
     * Root first, the folder itself last.
     *
     * @return list<array{id: int|null, name: string}>
     */
    public static function breadcrumb(?MediaFolder $folder): array
    {
        $trail = [];
        // Bounded: a corrupted parent cycle must not hang the request.
        for ($depth = 0; $folder !== null && $depth < 64; ++$depth) {
            array_unshift($trail, MediaView::folder($folder));
            $folder = $folder->getParent();
        }

        return $trail;
    }

    /** @return list<array{id: int|null, name: string}> */
    private function subfolders(?MediaFolder $parent): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('f')
            ->from(MediaFolder::class, 'f')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.name', 'ASC');
        $parent === null
            ? $qb->where('f.parent IS NULL')
            : $qb->where('f.parent = :parent')->setParameter('parent', $parent);

        /** @var list<MediaFolder> $folders */
        $folders = $qb->getQuery()->getResult();

        return array_map(MediaView::folder(...), $folders);
    }
}
