<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Asset\AssetResolverInterface;
use ZipStream\CompressionMethod;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

/**
 * Writes an export as a zip: `content.json` plus one `media/{hash}.{ext}` per
 * listed file, stored as-is. Sized before the first byte, one file in memory.
 *
 * @see docs/internals/transfer.md#the-zip
 *
 * @internal
 */
final class ZipExportWriter
{
    public const CONTENT = 'content.json';
    public const MEDIA_DIR = 'media/';

    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
    ) {
    }

    /** The entry a listed file travels under. */
    public static function mediaName(string $hash, string $extension): string
    {
        $extension = strtolower($extension);

        return self::MEDIA_DIR . $hash . '.'
            . (preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'bin');
    }

    /**
     * @param array<string, mixed> $payload what the exporter returned
     * @param resource             $output
     *
     * @return array{int, \Closure(): void} the exact size, and the sender
     */
    public function prepare(array $payload, bool $withMedia, $output): array
    {
        $zip = new ZipStream(
            operationMode: OperationMode::SIMULATE_STRICT,
            outputStream: $output,
            defaultCompressionMethod: CompressionMethod::STORE,
            defaultEnableZeroHeader: false,
            sendHttpHeaders: false,
            flushOutput: true,
        );
        $at = new \DateTimeImmutable();

        $json = json_encode(
            $payload,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
        $zip->addFileFromCallback(
            fileName: self::CONTENT,
            callback: static fn (): string => $json,
            lastModificationDateTime: $at,
            exactSize: \strlen($json),
        );

        $assets = $payload['assets'] ?? [];
        foreach ($withMedia && \is_array($assets) ? $assets : [] as $hash => $asset) {
            $zip->addFileFromCallback(
                fileName: self::mediaName((string) $hash, $asset['extension']),
                callback: fn (): string => $this->assetResolver->read($asset['path'])
                    ?? throw new \RuntimeException(sprintf('%s vanished during the export.', $asset['path'])),
                lastModificationDateTime: $at,
                exactSize: $asset['size'],
            );
        }

        return [$zip->finish(), $zip->executeSimulation(...)];
    }
}
