<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Image;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Twig\ImageExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The image seam: a passthrough default that must stay invisible, and a
 * `cb_image()` Twig function that hands the display box to whatever resolver a
 * host wired.
 */
final class ImageUrlResolverTest extends TestCase
{
    public function testPassthroughReturnsTheSourceWithNoResponsiveCandidates(): void
    {
        $resolved = (new PassthroughImageUrlResolver())->resolve('/uploads/photo.jpg', 800, 400);

        $this->assertSame('/uploads/photo.jpg', $resolved->src);
        $this->assertNull($resolved->srcset);
        $this->assertNull($resolved->sizes);
        $this->assertFalse($resolved->isResponsive());
    }

    public function testTwigFunctionForwardsTheDisplayBoxToTheResolver(): void
    {
        $spy = new class implements ImageUrlResolverInterface {
            /** @var list<array{string, int|null, int|null}> */
            public array $calls = [];

            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                $this->calls[] = [$src, $width, $height];

                return new ResolvedImage($src);
            }
        };

        $this->render($spy, '{{ cb_image("/a.jpg", 800, 400).src }}');
        $this->render($spy, '{{ cb_image("/b.jpg").src }}');

        $this->assertSame([['/a.jpg', 800, 400], ['/b.jpg', null, null]], $spy->calls);
    }

    /**
     * A block whose image field is empty renders a placeholder rather than an
     * <img>, but templates evaluate lazily and resolvers should not have to
     * defend against an empty path (a CDN builder would happily produce a URL
     * for it).
     */
    public function testEmptySourceShortCircuitsBeforeTheResolver(): void
    {
        $exploding = new class implements ImageUrlResolverInterface {
            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                throw new \LogicException('should not be called');
            }
        };

        $this->assertSame('', $this->render($exploding, '{{ cb_image("").src }}'));
        $this->assertSame('', $this->render($exploding, '{{ cb_image("   ").src }}'));
        $this->assertSame('', $this->render($exploding, '{{ cb_image(null).src }}'));
    }

    public function testResolverCandidatesReachTheTemplate(): void
    {
        $cdn = new class implements ImageUrlResolverInterface {
            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                return new ResolvedImage(
                    $src . '?w=' . ($width ?? 0),
                    $src . '?w=400 400w, ' . $src . '?w=800 800w',
                    '(max-width: 800px) 100vw, 800px',
                );
            }
        };

        $out = $this->render($cdn, '{% set i = cb_image("/p.jpg", 800) %}{{ i.src }}|{{ i.srcset }}|{{ i.sizes }}|{{ i.isResponsive ? "y" : "n" }}');

        $this->assertSame('/p.jpg?w=800|/p.jpg?w=400 400w, /p.jpg?w=800 800w|(max-width: 800px) 100vw, 800px|y', $out);
    }

    private function render(ImageUrlResolverInterface $resolver, string $template): string
    {
        $env = new Environment(new ArrayLoader(['t' => $template]));
        $env->addExtension(new ImageExtension($resolver));

        return $env->render('t');
    }
}
