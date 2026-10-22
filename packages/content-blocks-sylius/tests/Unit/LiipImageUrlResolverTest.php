<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Unit;

use ContentBlocks\Sylius\Image\LiipImageUrlResolver;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;

final class LiipImageUrlResolverTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/cb-sylius-images-' . bin2hex(random_bytes(4));
        mkdir($this->public . '/media/content-blocks', 0o777, true);
        foreach (['photo.jpg', 'logo.svg', 'loop.gif'] as $file) {
            touch($this->public . '/media/content-blocks/' . $file);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->public . '/media/content-blocks/*') ?: []);
        rmdir($this->public . '/media/content-blocks');
        rmdir($this->public . '/media');
        rmdir($this->public);
    }

    public function testAnUploadIsServedAtTheSizeOfItsBox(): void
    {
        $image = $this->resolver()->resolve('/media/content-blocks/photo.jpg', 400);

        self::assertSame('/cache/content_blocks_sylius_w400/media/content-blocks/photo.jpg', $image->src);
        // Up to twice the box, for dense screens.
        self::assertSame(
            '/cache/content_blocks_sylius_w400/media/content-blocks/photo.jpg 400w, '
            . '/cache/content_blocks_sylius_w800/media/content-blocks/photo.jpg 800w',
            $image->srcset,
        );
    }

    public function testAFluidViewGetsEveryCandidateAndAMiddleSrc(): void
    {
        $image = $this->resolver()->resolve('/media/content-blocks/photo.jpg');

        self::assertSame('/cache/content_blocks_sylius_w800/media/content-blocks/photo.jpg', $image->src);
        self::assertSame(4, substr_count((string) $image->srcset, 'w,') + 1);
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'absolute URL' => ['https://cdn.example.com/photo.jpg'];
        yield 'protocol-relative URL' => ['//cdn.example.com/photo.jpg'];
        yield 'missing file' => ['/media/content-blocks/gone.jpg'];
        yield 'climbing out' => ['/media/../../etc/passwd'];
        yield 'vector' => ['/media/content-blocks/logo.svg'];
        yield 'animated GIF' => ['/media/content-blocks/loop.gif'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untouched')]
    public function testAnythingElseIsPassedThrough(string $src): void
    {
        $image = $this->resolver()->resolve($src, 400);

        self::assertSame($src, $image->src);
        self::assertNull($image->srcset);
    }

    private function resolver(): LiipImageUrlResolver
    {
        $cache = $this->createMock(CacheManager::class);
        $cache->method('getBrowserPath')->willReturnCallback(
            static fn (string $path, string $filter): string => '/cache/' . $filter . '/' . $path,
        );

        return new LiipImageUrlResolver($cache, $this->public);
    }
}
