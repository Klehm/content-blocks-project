<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Storage;

use ContentBlocks\Asset\AssetGarbageCollector;
use ContentBlocks\Media\Asset\LibraryAssetReferenceProvider;
use ContentBlocks\Media\Storage\MediaRows;
use ContentBlocks\Media\Storage\RecordingFileStorage;
use ContentBlocks\Media\Storage\RecordingInventoriedFileStorage;
use ContentBlocks\Media\Storage\UploadRecorder;
use ContentBlocks\Media\Tests\Fixtures\DbalFailure;
use ContentBlocks\Media\Tests\Fixtures\InMemoryConnection;
use ContentBlocks\Media\Tests\Fixtures\TempUploads;
use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\LocalFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RecordingFileStorageTest extends TestCase
{
    private TempUploads $uploads;
    private InMemoryConnection $db;

    protected function setUp(): void
    {
        $this->uploads = new TempUploads();
        $this->db = new InMemoryConnection($this);
    }

    protected function tearDown(): void
    {
        $this->uploads->remove();
    }

    public function testAnUploadEntersTheLibraryWithWhatOnlyTheFileKnew(): void
    {
        $storage = $this->decorate($this->local());

        $path = $storage->upload($this->uploads->uploadedPng('Team photo.png'), 'blocks');

        $this->assertStringStartsWith('/uploads/blocks/', $path);
        $row = $this->db->rows[$path];
        $this->assertSame('Team photo', $row['title']);
        $this->assertSame(['image', 'image/png', 120, 80], [$row['kind'], $row['mime_type'], $row['width'], $row['height']]);
        $this->assertSame(\strlen(TempUploads::png()), $row['size']);
    }

    public function testAnImportedFileEntersTheLibraryToo(): void
    {
        $storage = $this->decorate($this->local());

        $path = $storage->uploadFromString(TempUploads::png(), 'png');

        $this->assertSame(['image', 'image/png', 120, 80], [
            $this->db->rows[$path]['kind'],
            $this->db->rows[$path]['mime_type'],
            $this->db->rows[$path]['width'],
            $this->db->rows[$path]['height'],
        ]);
    }

    public function testRemovingTheFileDropsItsRow(): void
    {
        $storage = $this->decorate($this->local());
        $path = $storage->uploadFromString(TempUploads::png(), 'png');

        $storage->remove($path);

        $this->assertSame([], $this->db->rows);
        $this->assertNull($storage->read($path));
    }

    public function testAFailedRowNeverFailsTheUpload(): void
    {
        $logged = [];
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ' ' . $context['path'];
            }
        };
        $this->db->failWith = DbalFailure::make();
        $storage = RecordingFileStorage::decorate(
            $this->local(),
            new UploadRecorder(new MediaRows($this->db->connection()), $logger),
        );

        $path = $storage->upload($this->uploads->uploadedPng(), 'blocks');

        $this->assertNotNull($storage->read($path));
        $this->assertSame(['error ' . $path], $logged);
    }

    // The asset GC tests `instanceof AssetInventoryInterface` on the storage.
    public function testTheDecoratorKeepsTheInventoryOfAStorageThatHasOne(): void
    {
        $decorated = $this->decorate($this->local());

        $this->assertInstanceOf(RecordingInventoriedFileStorage::class, $decorated);
        $this->assertTrue((new AssetGarbageCollector($decorated))->isSupported());
    }

    public function testAndClaimsNoneForAStorageWithout(): void
    {
        $decorated = $this->decorate($this->createMock(FileStorageInterface::class));

        $this->assertInstanceOf(RecordingFileStorage::class, $decorated);
        $this->assertNotInstanceOf(AssetInventoryInterface::class, $decorated);
    }

    public function testTheGcLeavesALibraryFileAlone(): void
    {
        $storage = $this->decorate($this->local());
        $path = $storage->uploadFromString(TempUploads::png(), 'png');
        touch($this->uploads->dir . substr($path, \strlen('/uploads')), time() - 90 * 86400);

        $gc = new AssetGarbageCollector($storage, [
            new LibraryAssetReferenceProvider(new MediaRows($this->db->connection())),
        ]);

        $this->assertSame([], $gc->collect(dryRun: false)->swept);
        $this->assertNotNull($storage->read($path));
    }

    public function testEverythingElseIsTheInnerStorage(): void
    {
        $inner = $this->createMock(FileStorageInterface::class);
        $inner->expects($this->once())->method('isStoredPath')->with('/x')->willReturn(true);
        $inner->expects($this->once())->method('read')->with('/x')->willReturn('bytes');

        $storage = $this->decorate($inner);

        $this->assertTrue($storage->isStoredPath('/x'));
        $this->assertSame('bytes', $storage->read('/x'));
    }

    private function local(): LocalFileStorage
    {
        return new LocalFileStorage($this->uploads->dir, '/uploads');
    }

    private function decorate(FileStorageInterface $inner): FileStorageInterface
    {
        return RecordingFileStorage::decorate(
            $inner,
            new UploadRecorder(new MediaRows($this->db->connection())),
        );
    }
}
