<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Controller\ImportExportController;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Service\ContentAreaExporter;
use ContentBlocks\Service\ContentAreaImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
    ): ImportExportController {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturn(false);

        return new ImportExportController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            new ContentAreaExporter($resolver),
            new ContentAreaImporter($resolver),
            $this->makeCsrfManager($csrfValid),
        );
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
                        'blocks' => [['type' => 'text', 'data' => ['content' => 'imported']]],
                    ]],
                ]],
            ],
            'assets' => [],
        ], \JSON_THROW_ON_ERROR);
    }

    // ---------- export ----------

    public function testExportStreamsAJsonAttachment(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $column = $this->makeColumn($section, 3);
        $block = $this->makeBlock($column, 4);
        $block->setDraftData(['content' => 'exported']);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->export(1);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="content-area-1-', (string) $response->headers->get('Content-Disposition'));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(ContentAreaExporter::FORMAT, $payload['format']);
        $this->assertSame(
            'exported',
            $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data']['content'],
        );
    }

    public function testExportReturns404ForAnUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->export(9);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testExportDeniesAccessWhenViewIsRefused(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canView')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->export(1);
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
