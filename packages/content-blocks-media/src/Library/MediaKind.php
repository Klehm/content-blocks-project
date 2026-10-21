<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Library;

/**
 * The kinds the upload widget asks a library about, plus `file` for what is
 * neither. Read off the MIME type when known, else off the extension.
 */
final class MediaKind
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const FILE = 'file';

    private const EXTENSIONS = [
        'png' => self::IMAGE, 'jpg' => self::IMAGE, 'jpeg' => self::IMAGE,
        'gif' => self::IMAGE, 'webp' => self::IMAGE, 'avif' => self::IMAGE,
        'svg' => self::IMAGE,
        'mp4' => self::VIDEO, 'webm' => self::VIDEO, 'ogv' => self::VIDEO,
        'ogg' => self::VIDEO, 'mov' => self::VIDEO,
    ];

    public static function of(?string $mimeType, string $path): string
    {
        $mimeType = strtolower((string) $mimeType);
        if (str_starts_with($mimeType, 'image/')) {
            return self::IMAGE;
        }
        if (str_starts_with($mimeType, 'video/')) {
            return self::VIDEO;
        }

        $extension = strtolower(pathinfo(parse_url($path, \PHP_URL_PATH) ?: $path, \PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension] ?? self::FILE;
    }

    /** The file name without its extension: what an editor recognises. */
    public static function titleOf(string $name): string
    {
        $base = pathinfo(parse_url($name, \PHP_URL_PATH) ?: $name, \PATHINFO_FILENAME);

        return mb_substr(trim($base) !== '' ? trim($base) : $name, 0, 255);
    }
}
