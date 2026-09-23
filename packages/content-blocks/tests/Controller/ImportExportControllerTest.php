<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Controller\ImportExportController;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Transfer\ContentAreaExporter;
use ContentBlocks\Transfer\ContentAreaImporter;
use ContentBlocks\Transfer\ContentAreaImporterInterface;
use ContentBlocks\Transfer\ImportSizeLimit;
use ContentBlocks\Transfer\ZipExportWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportExportControllerTest extends ControllerTestCase
{
    /** @var list<string> Temp files to unlink on teardown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
        ?AssetResolverInterface $resolver = null,
        int $importMaxSize = 50 * 1024 * 1024,
        ?ContentAreaImporterInterface $importer = null,
    ): ImportExportController {
        if ($resolver === null) {
            $resolver = $this->createMock(AssetResolverInterface::class);
            $resolver->method('isAssetPath')->willReturn(false);
        }

        return new ImportExportController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            new ContentAreaExporter($resolver),
            $importer ?? new ContentAreaImporter($resolver, $this->makeRegistry(), $this->makeDataKeys()),
            $this->makeCsrfManager($csrfValid),
            $this->makeJournal($em),
            new ImportSizeLimit($importMaxSize),
            new ZipExportWriter($resolver),
        );
    }

    /** Resolves `/uploads/…`, and reads only `/uploads/here.png`. */
    private function uploadsResolver(): AssetResolverInterface
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            static fn (string $v): bool => str_starts_with($v, '/uploads/'),
        );
        $resolver->method('read')->willReturnCallback(
            static fn (string $p): ?string => $p === '/uploads/here.png' ? 'bytes' : null,
        );

        return $resolver;
    }

    /** A POST request carrying `file` as an uploaded JSON document. */
    private function makeUploadRequest(string $content): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'cb-import-test-');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        $request = Request::create(
            '/_content-blocks/test',
            'POST',
            server: ['HTTP_X-CSRF-Token' => 'token'],
        );
        $request->files->set('file', new UploadedFile($path, 'export.json', 'application/json', null, true));

        return $request;
    }

    private function exportPayloadJson(): string
    {
        return json_encode([
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => [
                'sections' => [[
                    'layout' => 'full',
                    'columns' => [[
                        'preset' => 'col-12',
                        'blocks' => [['type' => 'fake', 'data' => ['content' => 'imported']]],
                    ]],
                ]],
            ],
            'assets' => [],
        ], \JSON_THROW_ON_ERROR);
    }

    // ---------- export ----------

    /**
     * Streams the response and reads the zip back.
     *
     * @return array<string, string> entry name => contents
     */
    private function unzip(Response $response): array
    {
        // Two levels: the writer flushes the inner buffer into the outer one.
        ob_start();
        ob_start();
        $response->sendContent();
        $inner = (string) ob_get_clean();
        $bytes = ob_get_clean() . $inner;
        $this->assertSame((int) $response->headers->get('Content-Length'), \strlen($bytes));

        $path = (string) tempnam(sys_get_temp_dir(), 'cb-export-test-');
        $this->tmpFiles[] = $path;
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    public function testExportStreamsAZipWithTheContentAndItsMedia(): void
    {
        $area = $this->makeArea(1);
        $block = $this->makeBlock($this->makeColumn($this->makeSection($area, 2), 3), 4);
        $block->setDraftData(['content' => '<img src="/uploads/here.png">']);
        $controller = $this->makeController($this->makeEm([$area]), resolver: $this->uploadsResolver());

        $response = $controller->export(1, new Request());

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="content-area-1-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.zip"', (string) $response->headers->get('Content-Disposition'));

        $entries = $this->unzip($response);
        $hash = hash('sha256', 'bytes');
        $this->assertSame(['content.json', 'media/' . $hash . '.png'], array_keys($entries));
        $this->assertSame('bytes', $entries['media/' . $hash . '.png']);
        $payload = json_decode($entries['content.json'], true);
        $this->assertSame(ContentAreaExporter::FORMAT, $payload['format']);
        $this->assertSame(
            '<img src="asset://' . $hash . '">',
            $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data']['content'],
        );
        $this->assertSame('/uploads/here.png', $payload['assets'][$hash]['path']);
    }

    public function testWithoutMediaTheZipHoldsOnlyTheContent(): void
    {
        $area = $this->makeArea(1);
        $block = $this->makeBlock($this->makeColumn($this->makeSection($area, 2), 3), 4);
        $block->setDraftData(['content' => '/uploads/here.png']);
        $controller = $this->makeController($this->makeEm([$area]), resolver: $this->uploadsResolver());

        $entries = $this->unzip($controller->export(1, new Request(['assets' => '0'])));

        $this->assertSame(['content.json'], array_keys($entries));
        $payload = json_decode($entries['content.json'], true);
        $this->assertSame('/uploads/here.png', $payload['assets'][hash('sha256', 'bytes')]['path']);
    }

    public function testTheSummaryCountsWhatTheExportWillHold(): void
    {
        $area = $this->makeArea(1);
        $column = $this->makeColumn($this->makeSection($area, 2), 3);
        $this->makeBlock($column, 4)->setDraftData(['content' => '/uploads/here.png']);
        $this->makeBlock($column, 5, 1)->setDraftData(['content' => 'text']);
        $controller = $this->makeController($this->makeEm([$area]), resolver: $this->uploadsResolver());

        $summary = json_decode((string) $controller->exportSummary(1)->getContent(), true);

        $this->assertSame(1, $summary['sectionCount']);
        $this->assertSame(2, $summary['blockCount']);
        $this->assertSame(1, $summary['mediaCount']);
        $this->assertSame(
            (int) $controller->export(1, new Request())->headers->get('Content-Length'),
            $summary['size'],
        );
        $this->assertSame(
            (int) $controller->export(1, new Request(['assets' => '0']))->headers->get('Content-Length'),
            $summary['sizeWithoutMedia'],
        );
    }

    public function testExportReturns404ForAnUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->export(9, new Request());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // An export holds the unpublished draft: viewing the page is not enough.
    public function testExportDeniesAViewerWhoCannotEdit(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController(
            $this->makeEm([$area]),
            accessChecker: $this->viewOnlyChecker(),
        );

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->export(1, new Request());
    }

    public function testExportSummaryDeniesAViewerWhoCannotEdit(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController(
            $this->makeEm([$area]),
            accessChecker: $this->viewOnlyChecker(),
        );

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->exportSummary(1);
    }

    private function viewOnlyChecker(): AccessCheckerInterface
    {
        $checker = $this->createMock(AccessCheckerInterface::class);
        $checker->method('canView')->willReturn(true);
        $checker->method('canEdit')->willReturn(false);

        return $checker;
    }

    // ---------- import ----------

    public function testImportReplacesTheDraftWithTheUploadedDocument(): void
    {
        $area = $this->makeArea(1);
        $existing = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->import(1, $this->makeUploadRequest($this->exportPayloadJson()));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['imported']);
        $this->assertSame(1, $payload['sectionCount']);
        $this->assertSame(0, $payload['skippedBlockCount']);
        $this->assertSame([], $payload['skippedBlockTypes']);
        $this->assertSame([], $payload['unknownFields']);
        $this->assertTrue($payload['hasUnpublishedChanges']);

        // Replace semantics: old section soft-deleted, imported one is a draft.
        $this->assertTrue($existing->isDeleted());
        $imported = $area->getSections()[1];
        $this->assertSame(
            ['content' => 'imported'],
            $imported->getColumns()[0]->getBlocks()[0]->getDraftData(),
        );
        $this->assertSame(1, $this->flushCount);
    }

    public function testImportSkipsUnknownBlockTypesWithoutFailing(): void
    {
        // Payloads come from other installations, so a block type this app
        // doesn't have is expected — it is left out and reported, not refused
        // and not imported as an inert placeholder.
        $area = $this->makeArea(1);
        $json = json_encode([
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => ['sections' => [[
                'layout' => 'full',
                'columns' => [['preset' => 'col-12', 'blocks' => [
                    ['type' => 'countdown', 'data' => ['ends' => 'soon']],
                ]]],
            ]]],
            'assets' => [],
        ], \JSON_THROW_ON_ERROR);

        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->import(1, $this->makeUploadRequest($json));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $payload['sectionCount']);
        $this->assertSame(1, $payload['skippedBlockCount']);
        $this->assertSame(['countdown'], $payload['skippedBlockTypes']);
        $this->assertSame(1, $this->flushCount, 'committed despite the warning');
        // The section came in, its only block did not.
        $this->assertCount(0, $area->getSections()[0]->getColumns()[0]->getBlocks());
    }

    public function testImportReportsTheMediaThisSiteDoesNotHave(): void
    {
        $area = $this->makeArea(1);
        $json = json_encode([
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => ['sections' => [[
                'layout' => 'full',
                'columns' => [['preset' => 'col-12', 'blocks' => [
                    ['type' => 'fake', 'data' => ['content' => '<img src="/uploads/here.png"><img src="/uploads/gone.png">']],
                    ['type' => 'fake', 'data' => ['content' => 'asset://deadbeef']],
                ]]],
            ]]],
            'assets' => [],
        ], \JSON_THROW_ON_ERROR);
        $controller = $this->makeController($this->makeEm([$area]), resolver: $this->uploadsResolver());

        $response = $controller->import(1, $this->makeUploadRequest($json));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(['/uploads/gone.png', 'asset://deadbeef'], $payload['missingAssets']);
        $this->assertSame(1, $this->flushCount, 'a warning, not a refusal');
    }

    public function testAFileOverTheConfiguredCapIsRefusedWith413(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), importMaxSize: 16);

        $response = $controller->import(1, $this->makeUploadRequest($this->exportPayloadJson()));

        $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(16, $payload['maxBytes']);
        $this->assertStringContainsString('content_blocks.import.max_size', $payload['error']);
        $this->assertSame(0, $this->flushCount);
    }

    public function testABodyPhpDroppedIsReportedAsTooLargeNotAsMissing(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));
        $postMax = ImportSizeLimit::postMaxSize();
        if ($postMax === null) {
            $this->markTestSkipped('post_max_size is unlimited here.');
        }
        $request = Request::create('/_content-blocks/test', 'POST', server: [
            'HTTP_X-CSRF-Token' => 'token',
            'CONTENT_LENGTH' => (string) ($postMax + 1),
        ]);

        $response = $controller->import(1, $request);

        $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    }

    public function testImportRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->import(1, $this->makeUploadRequest($this->exportPayloadJson()));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testImportReturns404ForAnUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->import(9, $this->makeUploadRequest($this->exportPayloadJson()));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testImportDeniesWriteWhenAccessCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->import(1, $this->makeUploadRequest($this->exportPayloadJson()));
    }

    public function testImportRequiresAFileUpload(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->import(1, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testImportRejectsInvalidJson(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->import(1, $this->makeUploadRequest('{not json'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('Invalid JSON', (string) $response->getContent());
    }

    // An ORM exception is an InvalidArgumentException too; its text can hold
    // an entity dump, so only the importer's own refusals are shown.
    public function testALibraryExceptionMessageIsNotSentToTheClient(): void
    {
        $area = $this->makeArea(1);
        $importer = $this->createMock(ContentAreaImporterInterface::class);
        $importer->method('import')->willThrowException(
            new \InvalidArgumentException('Entity ContentBlocks\\Entity\\Block@42 secret'),
        );
        $controller = $this->makeController($this->makeEm([$area]), importer: $importer);

        $response = $controller->import(1, $this->makeUploadRequest($this->exportPayloadJson()));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function testImportRejectsANonObjectPayload(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->import(1, $this->makeUploadRequest('"just a string"'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testImportMapsImporterValidationErrorsTo400(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->import(1, $this->makeUploadRequest(
            json_encode(['format' => 'wrong/v0'], \JSON_THROW_ON_ERROR),
        ));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('Unsupported format', (string) $response->getContent());
        $this->assertSame(0, $this->flushCount);
    }
}
