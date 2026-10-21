<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Storage;

use ContentBlocks\Media\Storage\MediaRecord;
use PHPUnit\Framework\TestCase;

final class MediaRecordTest extends TestCase
{
    public function testAnImageKeepsItsDimensionsAndTheNameItWasUploadedUnder(): void
    {
        $record = MediaRecord::describe('/u/9f8e.png', 'Logo.png', 'image/png', 1200, [120, 80]);

        $this->assertSame('image', $record->kind);
        $this->assertSame('Logo', $record->title);
        $this->assertSame([120, 80], [$record->width, $record->height]);
        $this->assertSame(1200, $record->size);
    }

    public function testWithoutANameTheTitleComesFromThePath(): void
    {
        $this->assertSame('9f8e', MediaRecord::describe('/u/9f8e.png', '', null, null)->title);
    }

    public function testAVideoHasNoDimensions(): void
    {
        $record = MediaRecord::describe('/u/c.mp4', 'c.mp4', 'video/mp4', 10, [640, 360]);

        $this->assertSame('video', $record->kind);
        $this->assertNull($record->width);
        $this->assertNull($record->height);
    }

    public function testAnUnreadableImageHasNoDimensions(): void
    {
        $this->assertNull(MediaRecord::describe('/u/a.png', '', 'image/png', 1, false)->width);
        $this->assertNull(MediaRecord::describe('/u/a.png', '', 'image/png', 1, [0, 0])->width);
    }

    public function testAnEmptyMimeTypeIsStoredAsUnknown(): void
    {
        $this->assertNull(MediaRecord::describe('/u/a.png', '', '', 1)->mimeType);
    }
}
