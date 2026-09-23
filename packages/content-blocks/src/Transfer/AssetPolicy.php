<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use Symfony\Component\Mime\MimeTypes;

/**
 * The upload endpoint's rules, applied to a file an import brings: size, MIME
 * type sniffed from the bytes, and the extension derived from that type.
 *
 * @see docs/internals/transfer.md#an-imported-file-is-an-upload
 *
 * @internal
 */
final class AssetPolicy
{
    /**
     * @param list<string> $uploadAllowedMimeTypes
     */
    public function __construct(
        private readonly int $uploadMaxSize = 10 * 1024 * 1024,
        private readonly array $uploadAllowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
        ],
    ) {
    }

    public function maxSize(): int
    {
        return $this->uploadMaxSize;
    }

    /**
     * The extension to store the bytes under. The claimed one is kept when
     * the sniffed type allows it (`jpeg` stays `jpeg`), never trusted alone.
     *
     * @throws \InvalidArgumentException when the file is refused
     */
    public function check(string $label, string $binary, string $claimedExtension = ''): string
    {
        if (\strlen($binary) > $this->uploadMaxSize) {
            throw new \InvalidArgumentException(sprintf('Asset %s is too large (max %d MB).', $label, intdiv($this->uploadMaxSize, 1024 * 1024), ));
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($binary);
        if (!\is_string($mime) || !\in_array($mime, $this->uploadAllowedMimeTypes, true)) {
            throw new \InvalidArgumentException(sprintf('Asset %s: file type "%s" is not allowed.', $label, \is_string($mime) ? $mime : 'unknown', ));
        }

        $known = MimeTypes::getDefault()->getExtensions($mime);
        $claimed = strtolower(ltrim($claimedExtension, '.'));
        if (\in_array($claimed, $known, true)) {
            return $claimed;
        }
        if ($known === []) {
            throw new \InvalidArgumentException(sprintf('Asset %s: no extension for "%s".', $label, $mime));
        }

        return $known[0];
    }
}
