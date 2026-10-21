<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Fixtures;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** A throwaway upload directory and real image bytes to put in it. */
final class TempUploads
{
    // A valid 120x80 PNG, so getimagesize() has something to read.
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

    public readonly string $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/cb-media-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/incoming', 0777, true);
    }

    public static function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    public function uploadedPng(string $clientName = 'Team photo.png'): UploadedFile
    {
        $path = $this->dir . '/incoming/' . bin2hex(random_bytes(4));
        file_put_contents($path, self::png());

        return new UploadedFile($path, $clientName, 'image/png', null, true);
    }

    public function remove(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }
}
