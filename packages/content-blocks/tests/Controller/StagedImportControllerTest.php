<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Controller\StagedImportController;
use ContentBlocks\Transfer\AssetPolicy;
use ContentBlocks\Transfer\ContentAreaExporter;
use ContentBlocks\Transfer\ContentAreaImporter;
use ContentBlocks\Transfer\ImportSizeLimit;
use ContentBlocks\Transfer\ImportStaging;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class StagedImportControllerTest extends ControllerTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Session $session;
    private RequestStack $requests;

    /** @var array<string, string> path => bytes the site holds */
    private array $files = [];

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requests = new RequestStack();
        $this->files = ['/uploads/here.png' => base64_decode(self::PNG)];
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tmpFiles, 'is_file'));
    }

    private function controller(EntityManagerInterface $em): StagedImportController
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            static fn (string $v): bool => str_starts_with($v, '/uploads/'),
        );
        $resolver->method('read')->willReturnCallback(fn (string $p): ?string => $this->files[$p] ?? null);
        $resolver->method('store')->willReturnCallback(function (string $bytes, string $ext): string {
            $path = sprintf('/uploads/stored-%d.%s', \count($this->files), $ext);
            $this->files[$path] = $bytes;

            return $path;
        });

        return new StagedImportController(
            $em,
            $this->makeAccessChecker(),
            new ContentAreaImporter($resolver, $this->makeRegistry(), $this->makeDataKeys()),
            $resolver,
            new AssetPolicy(),
            new ImportStaging($this->requests),
            $this->makeRegistry(),
            $this->makeCsrfManager(),
            $this->makeJournal($em),
        );
    }

    /** A request sharing the test's session, made current. */
    private function request(string $content = '', array $post = []): Request
    {
        $request = Request::create('/t', 'POST', $post, server: ['HTTP_X-CSRF-Token' => 'token'], content: $content);
        $request->setSession($this->session);
        $this->requests->push($request);

        return $request;
    }

    private function upload(string $bytes, string $name, ?string $hash = null): Request
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cb-staged-');
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;
        $request = $this->request(post: $hash === null ? [] : ['hash' => $hash]);
        $request->files->set('file', new UploadedFile($path, $name, null, null, true));

        return $request;
    }

    /** @return array<string, mixed> */
    private static function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    /** @param array<string, array<string, mixed>> $assets */
    private static function manifest(array $assets): string
    {
        $blocks = array_map(
            static fn (string $hash): array => ['type' => 'fake', 'data' => ['content' => 'asset://' . $hash]],
            array_keys($assets),
        );

        return json_encode([
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => ['sections' => [[
                'layout' => 'full',
                'columns' => [['preset' => 'col-12', 'blocks' => $blocks]],
            ]]],
            'assets' => $assets,
        ], \JSON_THROW_ON_ERROR);
    }

    public function testThePlanSplitsWhatThisSiteHoldsFromWhatMustBeSent(): void
    {
        $here = hash('sha256', $this->files['/uploads/here.png']);
        $far = hash('sha256', 'far');
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));

        $plan = self::json($controller->plan(1, $this->request(json_encode([
            'assets' => [
                $here => ['path' => '/uploads/here.png'],
                $far => ['path' => '/uploads/here.png'],
            ],
            'blockTypes' => ['fake', 'countdown'],
        ]))));

        $this->assertSame([$here], $plan['have']);
        $this->assertSame([$far], $plan['need']);
        $this->assertSame(['countdown'], $plan['unknownBlockTypes']);
        $this->assertSame((new ImportSizeLimit(10 * 1024 * 1024))->bytes(), $plan['maxAssetBytes']);
    }

    public function testAPlanRefusesAMalformedHash(): void
    {
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));

        $response = $controller->plan(1, $this->request('{"assets":{"../x":{}}}'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testASentFileIsCheckedStoredAndResolvedAtCommit(): void
    {
        $area = $this->makeArea(1);
        $existing = $this->makeSection($area, 2);
        $em = $this->makeEm([$area]);
        $controller = $this->controller($em);
        $bytes = base64_decode(self::PNG) . 'far';
        $hash = hash('sha256', $bytes);
        $manifest = self::manifest([$hash => ['extension' => 'png', 'size' => \strlen($bytes), 'path' => '/elsewhere/a.png']]);

        $controller->plan(1, $this->request($manifest));
        $sent = self::json($controller->asset(1, $this->upload($bytes, 'a.php', $hash)));
        $result = self::json($controller->commit(1, $this->request($manifest)));

        $this->assertSame('/uploads/stored-1.png', $sent['path'], 'extension from the bytes');
        $this->assertTrue($result['imported']);
        $this->assertSame([], $result['missingAssets']);
        $this->assertTrue($existing->isDeleted());
        $this->assertSame(
            ['content' => '/uploads/stored-1.png'],
            $area->getSections()[1]->getColumns()[0]->getBlocks()[0]->getDraftData(),
        );
        $this->assertSame(1, $this->flushCount);
    }

    public function testAFileWhoseBytesDoNotMatchItsHashIsRefused(): void
    {
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));
        $hash = hash('sha256', 'announced');
        $controller->plan(1, $this->request(self::manifest([$hash => ['path' => '/x.png']])));

        $response = $controller->asset(1, $this->upload(base64_decode(self::PNG), 'a.png', $hash));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('hash_mismatch', self::json($response)['code']);
    }

    public function testAFileThePlanDidNotListIsRefused(): void
    {
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));
        $controller->plan(1, $this->request(self::manifest([])));

        $response = $controller->asset(1, $this->upload(base64_decode(self::PNG), 'a.png'));

        $this->assertSame('unexpected_asset', self::json($response)['code']);
        $this->assertCount(1, $this->files, 'nothing stored');
    }

    public function testAScriptIsRefusedEvenWhenThePlanListsIt(): void
    {
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));
        $script = '<?php system($_GET["c"]);';
        $hash = hash('sha256', $script);
        $controller->plan(1, $this->request(self::manifest([$hash => ['path' => '/x.php']])));

        $response = $controller->asset(1, $this->upload($script, 'x.php'));

        $this->assertSame('refused', self::json($response)['code']);
        $this->assertCount(1, $this->files, 'nothing stored');
    }

    public function testAnInterruptedImportResumesWithoutSendingTwice(): void
    {
        $controller = $this->controller($this->makeEm([$this->makeArea(1)]));
        $bytes = base64_decode(self::PNG) . 'twice';
        $hash = hash('sha256', $bytes);
        $manifest = self::manifest([$hash => ['path' => '/elsewhere/a.png']]);
        $controller->plan(1, $this->request($manifest));
        $controller->asset(1, $this->upload($bytes, 'a.png'));

        $plan = self::json($controller->plan(1, $this->request($manifest)));

        $this->assertSame([$hash], $plan['have']);
    }

    public function testAFileNeverSentFallsBackToItsPathAndIsReported(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->controller($this->makeEm([$area]));
        $hash = hash('sha256', 'never sent');
        $manifest = self::manifest([$hash => ['path' => '/uploads/gone.png']]);
        $controller->plan(1, $this->request($manifest));

        $result = self::json($controller->commit(1, $this->request($manifest)));

        $this->assertSame(['/uploads/gone.png'], $result['missingAssets']);
    }

    public function testTheCommitEmptiesTheStaging(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->controller($this->makeEm([$area]));
        $bytes = base64_decode(self::PNG) . 'once';
        $hash = hash('sha256', $bytes);
        $manifest = self::manifest([$hash => ['path' => '/elsewhere/a.png']]);
        $controller->plan(1, $this->request($manifest));
        $controller->asset(1, $this->upload($bytes, 'a.png'));
        $controller->commit(1, $this->request($manifest));

        $response = $controller->asset(1, $this->upload($bytes, 'a.png'));

        $this->assertSame('unexpected_asset', self::json($response)['code']);
    }
}
