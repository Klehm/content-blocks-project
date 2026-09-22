<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

/**
 * The largest import file that can arrive: `content_blocks.import.max_size`,
 * capped by PHP's `upload_max_filesize` and `post_max_size`.
 *
 * @see docs/guide/host-services.md#large-imports
 *
 * @internal read by the import endpoint and the builder shell
 */
final class ImportSizeLimit
{
    public function __construct(
        private readonly int $importMaxSize = 50 * 1024 * 1024,
    ) {
    }

    public function bytes(): int
    {
        return $this->resolve()[0];
    }

    /** The setting that decides {@see bytes()}, to name in an error. */
    public function source(): string
    {
        return $this->resolve()[1];
    }

    /** What PHP accepts as a request body at all; null when unlimited. */
    public static function postMaxSize(): ?int
    {
        return self::iniBytes('post_max_size');
    }

    /**
     * @return array{int, string}
     */
    private function resolve(): array
    {
        $limit = [$this->importMaxSize, 'content_blocks.import.max_size'];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes($key);
            if ($bytes !== null && $bytes < $limit[0]) {
                $limit = [$bytes, $key];
            }
        }

        return $limit;
    }

    private static function iniBytes(string $key): ?int
    {
        $value = strtolower(trim((string) \ini_get($key)));
        if ($value === '' || (int) $value <= 0) {
            return null;
        }

        $bytes = (int) $value;

        return match (substr($value, -1)) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}
