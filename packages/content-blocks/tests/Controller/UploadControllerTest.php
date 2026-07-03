<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\UploadController;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\NullFileStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class UploadControllerTest extends ControllerTestCase
{
    /** Minimal real GIF so finfo-based MIME detection sees image/gif. */
    private const GIF_BYTES = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x00\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function testRejectsInvalidCsrfToken(): void
    {
        $controller = $this->makeUploadController(csrfValid: false);

        $response = $controller->upload($this->makeUploadRequest($this->makeGif()));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRejectsMissingFile(): void
    {
        $response = $this->makeUploadController()->upload($this->makeUploadRequest(null));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRejectsOversizedFile(): void
    {
        $controller = $this->makeUploadController(maxSize: 10); // bytes

        $response = $controller->upload($this->makeUploadRequest($this->makeGif()));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('too large', (string) $response->getContent());
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $controller = $this->makeUploadController(allowedMimeTypes: ['image/png']);

        $response = $controller->upload($this->makeUploadRequest($this->makeGif()));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('not allowed', (string) $response->getContent());
    }

    public function testStoresTheFileAndReturnsItsUrl(): void
    {
        $storage = new class implements FileStorageInterface {
            public ?string $uploadedDir = null;

            public function upload(UploadedFile $file, string $directory = ''): string
            {
                $this->uploadedDir = $directory;

                return '/uploads/content-blocks/blocks/abc.gif';
            }

            public function remove(string $path): void
            {
            }

            public function isStoredPath(string $value): bool
            {
                return false;
            }

            public function read(string $publicPath): ?string
            {
                return null;
            }

            public function uploadFromString(string $contents, string $extension, string $directory = ''): string
            {
                return '';
            }
        };

        $response = $this->makeUploadController(storage: $storage)
            ->upload($this->makeUploadRequest($this->makeGif()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['url' => '/uploads/content-blocks/blocks/abc.gif'],
            json_decode((string) $response->getContent(), true),
        );
        $this->assertSame('blocks', $storage->uploadedDir);
    }

    public function testDefaultNullStorageYields500(): void
    {
        // Storage not configured (NullFileStorage throws): clean 500, no leak.
        $response = $this->makeUploadController(storage: new NullFileStorage())
            ->upload($this->makeUploadRequest($this->makeGif()));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Upload failed'],
            json_decode((string) $response->getContent(), true),
        );
    }

    // -------- plumbing --------

    private function makeUploadController(
        ?FileStorageInterface $storage = null,
        bool $csrfValid = true,
        int $maxSize = 10 * 1024 * 1024,
        array $allowedMimeTypes = ['image/gif', 'image/png'],
    ): UploadController {
        return new UploadController(
            $storage ?? new NullFileStorage(),
            $this->makeCsrfManager($csrfValid),
            $maxSize,
            $allowedMimeTypes,
        );
    }

    private function makeGif(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cbgif');
        file_put_contents($tmp, self::GIF_BYTES);

        return new UploadedFile($tmp, 'pixel.gif', 'image/gif', null, test: true);
    }

    private function makeUploadRequest(?UploadedFile $file): Request
    {
        return Request::create(
            '/_content-blocks/upload',
            'POST',
            files: $file ? ['file' => $file] : [],
            server: ['HTTP_X-CSRF-Token' => 'token'],
        );
    }
}
