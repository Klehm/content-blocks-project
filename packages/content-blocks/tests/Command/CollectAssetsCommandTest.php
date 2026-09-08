<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Command;

use ContentBlocks\Asset\AssetGarbageCollector;
use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Command\CollectAssetsCommand;
use ContentBlocks\Storage\LocalFileStorage;
use ContentBlocks\Storage\NullFileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectAssetsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cb-gc-test-' . bin2hex(random_bytes(4));
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

    /**
     * @param list<string> $referenced
     */
    private function makeTester(LocalFileStorage $storage, array $referenced = []): CommandTester
    {
        $provider = $this->createMock(AssetReferenceProviderInterface::class);
        $provider->method('referencedAssetPaths')->willReturn($referenced);

        return new CommandTester(new CollectAssetsCommand(new AssetGarbageCollector($storage, [$provider])));
    }

    private function makeStorage(): LocalFileStorage
    {
        return new LocalFileStorage($this->dir, '/uploads/cb');
    }

    /** Ages a stored file past any plausible retention window. */
    private function age(string $publicPath): void
    {
        $absolute = $this->dir . substr($publicPath, \strlen('/uploads/cb'));
        touch($absolute, time() - 90 * 86400);
    }

    /**
     * The safety design in one test: the command reports without --force, and
     * the files are still there afterwards.
     */
    public function testWithoutForceItReportsAndDeletesNothing(): void
    {
        $storage = $this->makeStorage();
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        $this->age($orphan);

        $tester = $this->makeTester($storage);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
        $this->assertSame('orphan', $storage->read($orphan), 'the file must survive a run without --force');
    }

    public function testWithForceItDeletesTheUnreferencedFilesAndSparesTheRest(): void
    {
        $storage = $this->makeStorage();
        $kept = $storage->uploadFromString('kept', 'png', 'blocks');
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        $this->age($kept);
        $this->age($orphan);

        $tester = $this->makeTester($storage, [$kept]);
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('kept', $storage->read($kept));
        $this->assertNull($storage->read($orphan));
    }

    public function testAFreshUploadSurvivesEvenWithForce(): void
    {
        $storage = $this->makeStorage();
        $fresh = $storage->uploadFromString('fresh', 'png', 'blocks');

        $tester = $this->makeTester($storage);
        $tester->execute(['--force' => true]);

        $this->assertSame('fresh', $storage->read($fresh));
        $this->assertStringContainsString('Held by retention', $tester->getDisplay());
    }

    public function testJsonFormatSerializesTheWholeReport(): void
    {
        $storage = $this->makeStorage();
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        $this->age($orphan);

        $tester = $this->makeTester($storage);
        $tester->execute(['--format' => 'json']);

        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['dryRun']);
        $this->assertSame(1, $payload['scanned']);
        $this->assertSame($orphan, $payload['swept'][0]['path']);
        $this->assertSame(6, $payload['swept'][0]['size']);
    }

    public function testAStorageThatCannotEnumerateFailsLoudly(): void
    {
        $tester = new CommandTester(
            new CollectAssetsCommand(new AssetGarbageCollector(new NullFileStorage(), [])),
        );
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('cannot list its files', $tester->getDisplay());
    }

    public function testAnUnknownFormatIsRefusedBeforeAnythingRuns(): void
    {
        $tester = $this->makeTester($this->makeStorage());
        $tester->execute(['--format' => 'yaml']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testANonNumericRetentionIsRefused(): void
    {
        $tester = $this->makeTester($this->makeStorage());
        $tester->execute(['--retention' => 'forever']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testRetentionCanBeWidened(): void
    {
        $storage = $this->makeStorage();
        $orphan = $storage->uploadFromString('orphan', 'png', 'blocks');
        touch($this->dir . substr($orphan, \strlen('/uploads/cb')), time() - 45 * 86400);

        $tester = $this->makeTester($storage);
        $tester->execute(['--force' => true, '--retention' => '60']);

        $this->assertSame('orphan', $storage->read($orphan), '45 days old is inside a 60-day window');
    }
}
