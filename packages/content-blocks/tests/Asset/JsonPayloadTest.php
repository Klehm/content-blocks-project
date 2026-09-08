<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Asset;

use ContentBlocks\Asset\JsonPayload;
use PHPUnit\Framework\TestCase;

final class JsonPayloadTest extends TestCase
{
    /** What scalar hydration actually returns for a `json` column. */
    public function testDecodesARawJsonString(): void
    {
        $this->assertSame(
            ['src' => '/uploads/a.png', 'alt' => null],
            JsonPayload::decode('{"src": "/uploads/a.png", "alt": null}'),
        );
    }

    /** What an entity getter (or another hydration mode) returns. */
    public function testAnArrayPassesThroughUntouched(): void
    {
        $this->assertSame(['src' => '/uploads/a.png'], JsonPayload::decode(['src' => '/uploads/a.png']));
    }

    public function testEmptyAndUndecodableValuesBecomeAnEmptyArray(): void
    {
        $this->assertSame([], JsonPayload::decode(null));
        $this->assertSame([], JsonPayload::decode(''));
        $this->assertSame([], JsonPayload::decode('not json at all'));
        $this->assertSame([], JsonPayload::decode('"a bare json string"'));
        $this->assertSame([], JsonPayload::decode(42));
    }
}
