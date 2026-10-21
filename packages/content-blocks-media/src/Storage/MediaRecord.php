<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Storage;

use ContentBlocks\Media\Library\MediaKind;

/**
 * What is known of a file when it enters the library. Dimensions are read
 * from the bytes, so they are null for a video or an unreadable image.
 *
 * @internal
 */
final class MediaRecord
{
    public function __construct(
        public readonly string $path,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $mimeType = null,
        public readonly ?int $size = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * @param array{0: int, 1: int, ...}|false $imageSize from getimagesize*()
     */
    public static function describe(
        string $path,
        string $name,
        ?string $mimeType,
        ?int $size,
        array|false $imageSize = false,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        $kind = MediaKind::of($mimeType, $name !== '' ? $name : $path);
        $hasSize = $kind === MediaKind::IMAGE && $imageSize !== false
            && $imageSize[0] > 0 && $imageSize[1] > 0;

        return new self(
            path: $path,
            kind: $kind,
            title: MediaKind::titleOf($name !== '' ? $name : $path),
            mimeType: $mimeType !== null && $mimeType !== '' ? mb_substr($mimeType, 0, 127) : null,
            size: $size,
            width: $hasSize ? $imageSize[0] : null,
            height: $hasSize ? $imageSize[1] : null,
            createdAt: $createdAt,
        );
    }
}
