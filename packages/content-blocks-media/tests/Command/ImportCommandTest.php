<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Command;

use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Media\Asset\LibraryAssetReferenceProvider;
use ContentBlocks\Media\Command\ImportCommand;
use ContentBlocks\Media\Storage\MediaRecord;
use ContentBlocks\Media\Storage\MediaRows;
use ContentBlocks\Media\Storage\UploadRecorder;
use ContentBlocks\Media\Tests\Fixtures\ArrayStorage;
use ContentBlocks\Media\Tests\Fixtures\InMemoryConnection;
use ContentBlocks\Media\Tests\Fixtures\TempUploads;
use ContentBlocks\Storage\FileStorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCommandTest extends TestCase
{
    private InMemoryConnection $db;
    private ArrayStorage $storage;

    protected function setUp(): void
    {
        $this->db = new InMemoryConnection($this);
        $this->storage = new ArrayStorage([
            '/u/used.png' => TempUploads::png(),
            '/u/orphan.png' => TempUploads::png(),
            '/u/clip.mp4' => 'not really a video',
        ]);
    }

    public function testByDefaultOnlyTheFilesSomeContentUses(): void
    {
        $tester = $this->import([], ['/u/used.png', '/u/clip.mp4']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(['/u/used.png', '/u/clip.mp4'], array_keys($this->db->rows));
        $this->assertStringContainsString('1 file(s) no content uses', $tester->getDisplay());
    }

    public function testAllTakesTheUnreferencedOnesToo(): void
    {
        $this->import(['--all' => true], []);

        $this->assertCount(3, $this->db->rows);
    }

    public function testAnImportedImageGetsItsDimensionsAndItsStorageDate(): void
    {
        $this->import(['--all' => true], []);

        $image = $this->db->rows['/u/used.png'];
        $this->assertSame([120, 80], [$image['width'], $image['height']]);
        $this->assertSame('2025-03-04', $image['created_at']->format('Y-m-d'));
        // Only images are read; a video's bytes say nothing a row keeps.
        $this->assertSame(2, $this->storage->reads);
        $this->assertSame('video', $this->db->rows['/u/clip.mp4']['kind']);
    }

    public function testARunAgainAddsNothingAndReadsNothing(): void
    {
        $this->import(['--all' => true], []);
        $this->storage->reads = 0;

        $tester = $this->import(['--all' => true], []);

        $this->assertStringContainsString('Added 0 file(s)', $tester->getDisplay());
        $this->assertSame(0, $this->storage->reads);
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->import(['--all' => true, '--dry-run' => true], []);

        $this->assertSame([], $this->db->rows);
        $this->assertStringContainsString('Would add 3 file(s)', $tester->getDisplay());
    }

    // Its rows would make every file it already holds look used.
    public function testTheLibraryDoesNotVouchForItself(): void
    {
        (new MediaRows($this->db->connection()))->add(MediaRecord::describe('/u/used.png', '', null, 1));
        $library = new LibraryAssetReferenceProvider(new MediaRows($this->db->connection()));

        $tester = $this->command([$library])->execute([]);

        $this->assertSame(Command::SUCCESS, $tester);
        $this->assertSame(['/u/used.png'], array_keys($this->db->rows));
    }

    public function testAStorageThatCannotListItsFilesIsAnError(): void
    {
        $command = new ImportCommand(
            $this->createMock(FileStorageInterface::class),
            new MediaRows($this->db->connection()),
            new UploadRecorder(new MediaRows($this->db->connection())),
            [],
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('cannot list its', $tester->getDisplay());
    }

    /**
     * @param array<string, bool> $options
     * @param list<string>        $referenced
     */
    private function import(array $options, array $referenced): CommandTester
    {
        $provider = new class ($referenced) implements AssetReferenceProviderInterface {
            /** @param list<string> $paths */
            public function __construct(private readonly array $paths)
            {
            }

            public function referencedAssetPaths(): iterable
            {
                return $this->paths;
            }
        };
        $tester = $this->command([$provider]);
        $tester->execute($options);

        return $tester;
    }

    /** @param list<AssetReferenceProviderInterface> $providers */
    private function command(array $providers): CommandTester
    {
        $rows = new MediaRows($this->db->connection());

        return new CommandTester(new ImportCommand($this->storage, $rows, new UploadRecorder($rows), $providers));
    }
}
