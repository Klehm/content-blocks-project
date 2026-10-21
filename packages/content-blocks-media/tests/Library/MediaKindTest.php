<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Library;

use ContentBlocks\Media\Library\MediaKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaKindTest extends TestCase
{
    /** @return iterable<string, array{?string, string, string}> */
    public static function files(): iterable
    {
        yield 'image MIME' => ['image/png', '/u/a.bin', MediaKind::IMAGE];
        yield 'video MIME' => ['video/webm', '/u/a.bin', MediaKind::VIDEO];
        yield 'MIME wins over extension' => ['image/jpeg', '/u/a.mp4', MediaKind::IMAGE];
        yield 'no MIME, image extension' => [null, '/u/photo.JPG', MediaKind::IMAGE];
        yield 'no MIME, video extension' => [null, '/u/clip.mp4', MediaKind::VIDEO];
        yield 'an absolute URL' => [null, 'https://cdn.test/a.webp?v=2', MediaKind::IMAGE];
        yield 'neither' => ['application/pdf', '/u/doc.pdf', MediaKind::FILE];
    }

    #[DataProvider('files')]
    public function testTheKindOfAFile(?string $mime, string $path, string $kind): void
    {
        $this->assertSame($kind, MediaKind::of($mime, $path));
    }

    public function testTheTitleIsTheFileNameWithoutItsExtension(): void
    {
        $this->assertSame('Team photo', MediaKind::titleOf('Team photo.png'));
        $this->assertSame('a1b2c3', MediaKind::titleOf('/uploads/blocks/a1b2c3.webp'));
        $this->assertSame(255, mb_strlen(MediaKind::titleOf(str_repeat('é', 300) . '.png')));
    }
}
