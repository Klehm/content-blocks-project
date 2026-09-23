<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;

/**
 * Export half of the asset convention: stored paths out, `asset://{hash}`
 * tokens in, a manifest of the files. One instance per export, never a service.
 *
 * @see docs/internals/transfer.md#assets-travel-beside-the-content
 */
final class AssetTokenizer
{
    /** Written here, read by {@see AssetRewriter} — one home for the string. */
    public const TOKEN_PREFIX = 'asset://';

    /**
     * @var array<string, array{
     *     mimeType: string,
     *     extension: string,
     *     size: int,
     *     path: string,
     * }>
     */
    private array $assets = [];

    /** @var array<string, string> path => hash, so a file is read once */
    private array $hashes = [];

    public function __construct(
        private readonly AssetReferenceCollector $collector,
        private readonly AssetResolverInterface $assetResolver,
    ) {
    }

    /**
     * Replaces every asset reference in $value with its token, registering
     * the file under that hash. Identical files are listed once.
     */
    public function tokenize(mixed $value): mixed
    {
        return $this->collector->map($value, function (string $path): string {
            $hash = $this->hashes[$path] ??= $this->register($path);

            // Missing on disk: the path stays, so the import side sees a
            // broken reference rather than a silently dropped field.
            return $hash === '' ? $path : self::TOKEN_PREFIX . $hash;
        });
    }

    /**
     * Read once the whole payload is walked — an extension contributing late
     * still gets its files listed.
     *
     * @return array<string, array{
     *     mimeType: string,
     *     extension: string,
     *     size: int,
     *     path: string,
     * }>
     */
    public function assets(): array
    {
        return $this->assets;
    }

    private function register(string $path): string
    {
        $binary = $this->assetResolver->read($path);
        if ($binary === null) {
            return '';
        }

        $hash = hash('sha256', $binary);
        if (!isset($this->assets[$hash])) {
            $extension = pathinfo($path, \PATHINFO_EXTENSION);
            $this->assets[$hash] = [
                'mimeType' => $this->guessMime($binary),
                'extension' => $extension !== '' ? strtolower($extension) : 'bin',
                'size' => \strlen($binary),
                'path' => $path,
            ];
        }

        return $hash;
    }

    private function guessMime(string $binary): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
