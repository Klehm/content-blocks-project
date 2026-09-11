<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;

/**
 * Export half of the asset convention: stored paths out, `asset://{hash}`
 * tokens in, bytes accumulated. One instance per export, never a service.
 *
 * @see docs/internals/transfer.md#assets-travel-as-bytes-not-paths
 */
final class AssetTokenizer
{
    /** Written here, read by {@see AssetRewriter} — one home for the string. */
    public const TOKEN_PREFIX = 'asset://';

    /**
     * @var array<string, array{
     *     mimeType: string,
     *     extension: string,
     *     data: string,
     * }>
     */
    private array $assets = [];

    public function __construct(
        private readonly AssetReferenceCollector $collector,
        private readonly AssetResolverInterface $assetResolver,
    ) {
    }

    /**
     * Replaces every asset reference in $value with its token, registering the
     * binary under that hash. Identical binaries are stored once.
     */
    public function tokenize(mixed $value): mixed
    {
        return $this->collector->map($value, function (string $path): string {
            $binary = $this->assetResolver->read($path);
            if ($binary === null) {
                // Missing on disk — keep the path so the import side sees a
                // broken reference rather than a silently dropped field.
                return $path;
            }

            $hash = hash('sha256', $binary);
            if (!isset($this->assets[$hash])) {
                $extension = pathinfo($path, \PATHINFO_EXTENSION);
                $this->assets[$hash] = [
                    'mimeType' => $this->guessMime($binary),
                    'extension' => is_string($extension) && $extension !== '' ? $extension : 'bin',
                    'data' => base64_encode($binary),
                ];
            }

            return self::TOKEN_PREFIX . $hash;
        });
    }

    /**
     * Read once the whole payload is walked — an extension contributing late
     * still gets its bytes carried.
     *
     * @return array<string, array{
     *     mimeType: string,
     *     extension: string,
     *     data: string,
     * }>
     */
    public function assets(): array
    {
        return $this->assets;
    }

    private function guessMime(string $binary): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
