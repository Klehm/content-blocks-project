<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Storage;

use ContentBlocks\Storage\LocalFileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class LocalFileStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cb-storage-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function makeStorage(): LocalFileStorage
    {
        return new LocalFileStorage($this->dir, '/uploads/cb');
    }

    public function testUploadStoresTheFileAndReturnsAPublicPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cb');
        file_put_contents($tmp, 'GIF89a-not-really');
        $file = new UploadedFile($tmp, 'photo.gif', 'image/gif', null, test: true);

        $path = $this->makeStorage()->upload($file, 'blocks');

        $this->assertStringStartsWith('/uploads/cb/blocks/', $path);
        $this->assertFileExists($this->dir . '/blocks/' . basename($path));
    }

    public function testUploadFromStringRoundTripsThroughReadAndRemove(): void
    {
        $storage = $this->makeStorage();

        $path = $storage->uploadFromString('binary-ish contents', 'png', 'blocks');

        $this->assertTrue($storage->isStoredPath($path));
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame('binary-ish contents', $storage->read($path));

        $storage->remove($path);
        $this->assertNull($storage->read($path));
    }

    public function testIsStoredPathRejectsForeignPaths(): void
    {
        $storage = $this->makeStorage();

        $this->assertFalse($storage->isStoredPath('/other/prefix/x.png'));
        $this->assertFalse($storage->isStoredPath('https://cdn.example.com/x.png'));
        $this->assertNull($storage->read('/other/prefix/x.png'));
    }
}
