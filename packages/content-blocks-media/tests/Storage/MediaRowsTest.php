<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Storage;

use ContentBlocks\Media\Storage\MediaRecord;
use ContentBlocks\Media\Storage\MediaRows;
use ContentBlocks\Media\Tests\Fixtures\InMemoryConnection;
use PHPUnit\Framework\TestCase;

final class MediaRowsTest extends TestCase
{
    public function testAFileIsInTheLibraryOnce(): void
    {
        $db = new InMemoryConnection($this);
        $rows = new MediaRows($db->connection());
        $record = MediaRecord::describe('/u/a.png', 'a.png', 'image/png', 3, [1, 1]);

        $this->assertTrue($rows->add($record));
        $this->assertFalse($rows->add($record));
        $this->assertCount(1, $db->rows);
    }

    public function testTheRowStartsWithAnEmptyAltAndTheRecordedFacts(): void
    {
        $db = new InMemoryConnection($this);
        (new MediaRows($db->connection()))
            ->add(MediaRecord::describe('/u/a.png', 'Logo.png', 'image/png', 3, [120, 80]));

        $row = $db->rows['/u/a.png'];
        $this->assertSame('', $row['alt']);
        $this->assertSame('Logo', $row['title']);
        $this->assertSame(['image', 120, 80], [$row['kind'], $row['width'], $row['height']]);
        $this->assertInstanceOf(\DateTimeImmutable::class, $row['created_at']);
    }

    public function testAnImportedFileKeepsTheDateItWasStored(): void
    {
        $db = new InMemoryConnection($this);
        $storedAt = new \DateTimeImmutable('2025-01-02 03:04:05');
        (new MediaRows($db->connection()))->add(MediaRecord::describe('/u/a.png', '', null, 1, false, $storedAt));

        $this->assertSame($storedAt, $db->rows['/u/a.png']['created_at']);
    }

    public function testPathsAndRemoval(): void
    {
        $db = new InMemoryConnection($this);
        $rows = new MediaRows($db->connection());
        $rows->add(MediaRecord::describe('/u/a.png', '', null, 1));
        $rows->add(MediaRecord::describe('/u/b.png', '', null, 1));

        $rows->remove('/u/a.png');

        $this->assertSame(['/u/b.png'], iterator_to_array($rows->paths(), false));
        $this->assertFalse($rows->has('/u/a.png'));
    }
}
