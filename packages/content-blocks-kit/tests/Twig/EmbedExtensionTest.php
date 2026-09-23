<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Twig;

use ContentBlocks\Kit\Twig\EmbedExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmbedExtensionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function recognized(): iterable
    {
        $yt = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
        $vimeo = 'https://player.vimeo.com/video/76979871';

        yield 'bare id' => ['dQw4w9WgXcQ', $yt];
        yield 'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', $yt];
        yield 'watch, v not first' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ', $yt];
        yield 'watch with timestamp' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s', $yt];
        yield 'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', $yt];
        yield 'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', $yt];
        yield 'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', $yt];
        yield 'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ', $yt];
        yield 'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', $yt];
        yield 'no-cookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $yt];
        yield 'padded' => ["  https://youtu.be/dQw4w9WgXcQ \n", $yt];
        yield 'vimeo' => ['https://vimeo.com/76979871', $vimeo];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871', $vimeo];
    }

    #[DataProvider('recognized')]
    public function testRecognizedUrlsBecomeAPlayerUrl(string $url, string $expected): void
    {
        $this->assertSame($expected, (new EmbedExtension())->buildEmbedUrl($url));
    }

    /** @return iterable<string, array{string}> */
    public static function unrecognized(): iterable
    {
        yield 'empty' => [''];
        yield 'other provider' => ['https://www.dailymotion.com/video/x7tgad0'];
        yield 'youtube channel' => ['https://www.youtube.com/@somechannel'];
        yield 'too short an id' => ['https://youtu.be/abc'];
        yield 'script' => ['javascript:alert(1)'];
    }

    #[DataProvider('unrecognized')]
    public function testAnythingElseIsNull(string $url): void
    {
        $this->assertNull((new EmbedExtension())->buildEmbedUrl($url));
    }

    // Whatever the input, the output only ever points at the two players.
    public function testTheOutputHostIsAlwaysAKnownPlayer(): void
    {
        $url = (new EmbedExtension())->buildEmbedUrl('https://evil.example/youtu.be/dQw4w9WgXcQ');

        $this->assertStringStartsWith('https://www.youtube.com/embed/', (string) $url);
    }
}
