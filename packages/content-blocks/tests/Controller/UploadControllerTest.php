<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\UploadController;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
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
        $this->assertSame('csrf', $this->body($response)['code']);
    }

    // The token alone is not a right to write files: the area is checked.
    public function testRefusesAnUploadWithoutAnArea(): void
    {
        $controller = $this->makeUploadController();

        $response = $controller->upload($this->makeUploadRequest($this->makeGif(), null));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRefusesAnUploadForAnAreaTheUserCannotEdit(): void
    {
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeUploadController(accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->upload($this->makeUploadRequest($this->makeGif()));
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
        $this->assertSame('too_large', $this->body($response)['code']);
    }

    // The editor reads the error; a script reads the code, in any language.
    public function testTheErrorIsTranslatedWithTheFileAndTheLimit(): void
    {
        $gif = $this->makeGif();
        $request = $this->makeUploadRequest(new UploadedFile($gif->getPathname(), 'poster.gif', 'image/gif', null, true));
        $controller = $this->makeUploadController(maxSize: 10);

        $body = $this->body($controller->upload($request));

        $this->assertSame('too_large', $body['code']);
        $this->assertStringStartsWith('cb.upload.error.too_large {"%name%":"poster.gif","%max%":', $body['error']);
        $this->assertSame($body['error'], $body['message']);
    }

    // PHP refused it first (upload_max_filesize): no ini wording leaks out.
    public function testAFileTooLargeForPhpIsSaidInTheSameWords(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cbbig');
        $refused = new UploadedFile($tmp, 'huge.png', 'image/png', \UPLOAD_ERR_INI_SIZE, true);

        $body = $this->body($this->makeUploadController()->upload($this->makeUploadRequest($refused)));

        $this->assertSame('too_large', $body['code']);
        $this->assertStringNotContainsString('ini', $body['error']);
        $this->assertStringContainsString('huge.png', $body['error']);
    }

    // Over post_max_size PHP empties the whole body, the area included: that
    // is a file too large, not a missing area.
    public function testABodyPhpDroppedIsAFileTooLarge(): void
    {
        $request = Request::create('/_content-blocks/upload', 'POST', server: [
            'HTTP_X-CSRF-Token' => 'token',
            'CONTENT_LENGTH' => (string) (30 * 1024 * 1024),
        ]);

        $response = $this->makeUploadController()->upload($request);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('too_large', $this->body($response)['code']);
        $this->assertStringStartsWith('cb.upload.error.too_large_unnamed', $this->body($response)['error']);
    }

    public function testAnEmptyPostIsStillAMissingArea(): void
    {
        $request = Request::create('/_content-blocks/upload', 'POST', server: ['HTTP_X-CSRF-Token' => 'token']);

        $this->assertSame('area_not_found', $this->body($this->makeUploadController()->upload($request))['code']);
    }

    public function testAnotherPhpUploadErrorIsAFailedUpload(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cbpart');
        $partial = new UploadedFile($tmp, 'half.png', 'image/png', \UPLOAD_ERR_PARTIAL, true);

        $body = $this->body($this->makeUploadController()->upload($this->makeUploadRequest($partial)));

        $this->assertSame('failed', $body['code']);
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $controller = $this->makeUploadController(allowedMimeTypes: ['image/png']);

        $response = $controller->upload($this->makeUploadRequest($this->makeGif()));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('type_not_allowed', $this->body($response)['code']);
        $this->assertStringContainsString('"%type%":"image\\/gif"', $this->body($response)['error']);
    }

    // A scripted SVG served from the site's origin is stored XSS.
    public function testTheDefaultPolicyRefusesSvg(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cbsvg');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg" '
            . 'onload="alert(1)"/>');
        $svg = new UploadedFile($tmp, 'x.svg', 'image/svg+xml', null, test: true);
        $controller = new UploadController(
            new NullFileStorage(),
            $this->makeCsrfManager(true),
            $this->makeEm([$this->makeArea(1)]),
            $this->makeAccessChecker(),
        );

        $response = $controller->upload($this->makeUploadRequest($svg));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('type_not_allowed', $this->body($response)['code']);
    }

    public function testTheBundleDefaultsDoNotListSvg(): void
    {
        $policy = new \ContentBlocks\Transfer\AssetPolicy();

        $this->expectException(\InvalidArgumentException::class);
        $policy->check('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg');
    }

    public function testStoresTheFileAndReturnsItsUrl(): void
    {
        $storage = new class () implements FileStorageInterface {
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
        $this->assertSame('failed', $this->body($response)['code']);
    }

    // -------- plumbing --------

    private function makeUploadController(
        ?FileStorageInterface $storage = null,
        bool $csrfValid = true,
        int $maxSize = 10 * 1024 * 1024,
        array $allowedMimeTypes = ['image/gif', 'image/png'],
        ?AccessCheckerInterface $accessChecker = null,
    ): UploadController {
        return new UploadController(
            $storage ?? new NullFileStorage(),
            $this->makeCsrfManager($csrfValid),
            $this->makeEm([$this->makeArea(1)]),
            $accessChecker ?? $this->makeAccessChecker(),
            $maxSize,
            $allowedMimeTypes,
            new class () implements \Symfony\Contracts\Translation\TranslatorInterface {
                /** @param array<string, string> $parameters */
                public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
                {
                    return $id . ' ' . json_encode($parameters);
                }

                public function getLocale(): string
                {
                    return 'en';
                }
            },
        );
    }

    /** @return array<string, mixed> */
    private function body(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function makeGif(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cbgif');
        file_put_contents($tmp, self::GIF_BYTES);

        return new UploadedFile($tmp, 'pixel.gif', 'image/gif', null, test: true);
    }

    private function makeUploadRequest(?UploadedFile $file, ?string $area = '1'): Request
    {
        return Request::create(
            '/_content-blocks/upload',
            'POST',
            $area === null ? [] : ['area' => $area],
            files: $file ? ['file' => $file] : [],
            server: ['HTTP_X-CSRF-Token' => 'token'],
        );
    }
}
