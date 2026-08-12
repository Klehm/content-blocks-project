<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Twig\ImageExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The image block is not the only place the kit renders a picture: gallery items
 * and card media do too, and a seam that covered one of the three would leave a
 * host's optimization half-applied.
 *
 * These two views are fluid — the width of a grid cell is a CSS matter — so they
 * hand the resolver the source alone and never derive `sizes` themselves.
 */
final class GalleryCardImageSeamTest extends TestCase
{
    public function testGalleryItemsGoThroughTheResolver(): void
    {
        $resolver = new RecordingResolver();
        $html = $this->render('gallery', [
            'items' => [
                ['src' => '/a.jpg', 'alt' => 'A'],
                ['src' => '/b.jpg'],
            ],
        ], $resolver);

        $this->assertSame([['/a.jpg', null, null], ['/b.jpg', null, null]], $resolver->calls);
        $this->assertStringContainsString('src="/a.jpg?cdn"', $html);
        $this->assertStringContainsString('srcset="/a.jpg 800w"', $html);
        $this->assertStringContainsString('sizes="60vw"', $html);
    }

    public function testCardMediaGoesThroughTheResolver(): void
    {
        $resolver = new RecordingResolver();
        $html = $this->render('card', [
            'items' => [['src' => '/hero.jpg', 'title' => 'Hero']],
        ], $resolver);

        $this->assertSame([['/hero.jpg', null, null]], $resolver->calls);
        $this->assertStringContainsString('src="/hero.jpg?cdn"', $html);
        $this->assertStringContainsString('srcset="/hero.jpg 800w"', $html);
    }

    #[DataProvider('imageBearingBlocks')]
    public function testPassthroughKeepsTodaysMarkup(string $block): void
    {
        $html = $this->render($block, ['items' => [['src' => '/a.jpg', 'title' => 'A']]]);

        $this->assertStringContainsString('src="/a.jpg"', $html);
        $this->assertStringNotContainsString('srcset', $html);
        $this->assertStringNotContainsString('sizes', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function imageBearingBlocks(): iterable
    {
        yield 'gallery' => ['gallery'];
        yield 'card' => ['card'];
    }

    /** @param array<string, mixed> $data */
    private function render(string $block, array $data, ?ImageUrlResolverInterface $resolver = null): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new ImageExtension($resolver ?? new PassthroughImageUrlResolver()));
        // The gallery's slider arrows call the kit's icon helper; the grid
        // layout under test does not, but the function must still exist.
        $env->addFunction(new \Twig\TwigFunction('cb_kit_icon', static fn (string $name, int $size = 24): string => ''));

        return $env->render("@ContentBlocksKit/block/{$block}/view.html.twig", ['data' => $data]);
    }
}

final class RecordingResolver implements ImageUrlResolverInterface
{
    /** @var list<array{string, int|null, int|null}> */
    public array $calls = [];

    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        $this->calls[] = [$src, $width, $height];

        return new ResolvedImage($src . '?cdn', $src . ' 800w', '60vw');
    }
}
