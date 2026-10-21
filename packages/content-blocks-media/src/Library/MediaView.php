<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Library;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;

/**
 * The JSON the picker reads. A thumbnail goes through the host's image
 * resolver, so a library of camera photos is not downloaded at full size.
 */
final class MediaView
{
    public const THUMB_WIDTH = 320;

    /** @return array<string, mixed> */
    public static function media(Media $media, ImageUrlResolverInterface $images): array
    {
        $thumb = null;
        if ($media->getKind() === MediaKind::IMAGE) {
            $resolved = $images->resolve($media->getPath(), self::THUMB_WIDTH);
            $thumb = ['src' => $resolved->src, 'srcset' => $resolved->srcset];
        }

        return [
            'id' => $media->getId(),
            'path' => $media->getPath(),
            'title' => $media->getTitle(),
            'alt' => $media->getAlt(),
            'kind' => $media->getKind(),
            'mimeType' => $media->getMimeType(),
            'size' => $media->getSize(),
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'createdAt' => $media->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'folder' => $media->getFolder()?->getId(),
            'thumb' => $thumb,
        ];
    }

    /** @return array{id: int|null, name: string} */
    public static function folder(MediaFolder $folder): array
    {
        return ['id' => $folder->getId(), 'name' => $folder->getName()];
    }
}
