<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Asset\AllowAllAssetReportViewer;
use ContentBlocks\Asset\AssetGarbageCollector;
use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Asset\DenyAllAssetReportViewer;
use ContentBlocks\Controller\AssetReportController;
use ContentBlocks\Storage\LocalFileStorage;
use ContentBlocks\Storage\NullFileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AssetReportControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cb-report-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    private function makeTwig(): Environment
    {
        $loader = new FilesystemLoader(\dirname(__DIR__, 2) . '/templates');
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocks');

        return new Environment($loader);
    }

    /**
     * @param list<string> $referenced
     */
    private function makeController(
        LocalFileStorage|NullFileStorage $storage,
        array $referenced = [],
        bool $allowed = true,
    ): AssetReportController {
        $provider = $this->createMock(AssetReferenceProviderInterface::class);
        $provider->method('referencedAssetPaths')->willReturn($referenced);

        return new AssetReportController(
            new AssetGarbageCollector($storage, [$provider]),
            $allowed ? new AllowAllAssetReportViewer() : new DenyAllAssetReportViewer(),
            $this->makeTwig(),
        );
    }

    private function makeStorage(): LocalFileStorage
    {
        return new LocalFileStorage($this->dir, '/uploads/cb');
    }

    private function age(string $publicPath): void
    {
        touch($this->dir . substr($publicPath, \strlen('/uploads/cb')), time() - 90 * 86400);
    }

    /**
     * 404 rather than 403: an install that never opted in should not advertise
     * that a whole-install asset report exists behind this URL.
     */
    public function testTheReportIsDeniedByDefaultAndHiddenRatherThanForbidden(): void
    {
        $controller = $this->makeController($this->makeStorage(), allowed: false);

        $this->expectException(NotFoundHttpException::class);
        $controller->report(new Request());
    }

    public function testItListsUnreferencedFilesAndSparesTheReferencedOnes(): void
    {
        $storage = $this->makeStorage();
        $kept = $storage->uploadFromString('kept', 'png', 'blocks');
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        $this->age($kept);
        $this->age($orphan);

        $response = $this->makeController($storage, [$kept])->report(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $this->assertStringContainsString($orphan, $html);
        $this->assertStringNotContainsString($kept, $html);
    }

    /**
     * The page is a report, not a control panel — the reclaiming act stays in
     * a shell. If a delete affordance ever appears here, this test is the one
     * that should stop it.
     */
    public function testThePageOffersNoWayToDeleteAnythingAndTheFilesSurviveRenderingIt(): void
    {
        $storage = $this->makeStorage();
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        $this->age($orphan);

        $html = (string) $this->makeController($storage)->report(new Request())->getContent();

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringContainsString('content-blocks:assets:gc', $html);
        $this->assertSame('orphan', $storage->read($orphan), 'viewing the report must not sweep');
    }

    public function testRetentionCanBeWidenedFromTheQueryString(): void
    {
        $storage = $this->makeStorage();
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        touch($this->dir . substr($orphan, \strlen('/uploads/cb')), time() - 45 * 86400);

        $html = (string) $this->makeController($storage)
            ->report(new Request(['retention' => '60']))
            ->getContent();

        $this->assertStringContainsString('Held back — younger than 60 days (1)', $html);
    }

    public function testAnInvalidRetentionFallsBackToTheDefault(): void
    {
        $html = (string) $this->makeController($this->makeStorage())
            ->report(new Request(['retention' => 'forever']))
            ->getContent();

        $this->assertStringContainsString(
            '--retention=' . AssetGarbageCollector::DEFAULT_RETENTION_DAYS,
            $html,
        );
    }

    public function testJsonFormatReturnsTheSerializedReport(): void
    {
        $storage = $this->makeStorage();
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        $this->age($orphan);

        $response = $this->makeController($storage)->report(new Request(['format' => 'json']));

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['dryRun']);
        $this->assertSame($orphan, $payload['swept'][0]['path']);
    }

    public function testAStorageThatCannotEnumerateExplainsItselfInsteadOfErroring(): void
    {
        $response = $this->makeController(new NullFileStorage())->report(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('cannot list its files', (string) $response->getContent());
    }
}
