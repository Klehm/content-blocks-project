<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Twig\ImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the image view template to guard its horizontal alignment.
 *
 * Regression guard: the display width used to be applied as `max-width` on the
 * <figure>, which then sat at the column's left edge — so the alignment classes
 * (`align-items` on the figure) had nothing to move and left/center/right all
 * looked identical. The width now caps the <img> instead, keeping the figure a
 * full-width flex column so the alignment class actually positions the image.
 */
final class ImageViewTest extends TestCase
{
    public function testAlignmentClassMirrorsTheStoredValue(): void
    {
        $this->assertStringContainsString('cb-kit-image--start', $this->render(['align' => 'start']));
        $this->assertStringContainsString('cb-kit-image--center', $this->render(['align' => 'center']));
        $this->assertStringContainsString('cb-kit-image--end', $this->render(['align' => 'end']));
    }

    public function testInvalidAlignmentFallsBackToCenter(): void
    {
        $html = $this->render(['align' => 'bogus']);

        $this->assertStringContainsString('cb-kit-image--center', $html);
        $this->assertStringNotContainsString('cb-kit-image--bogus', $html);
    }

    /**
     * The whole point of the fix: the width lives on the <img>, never on the
     * <figure>, so the figure can stay full-width and align its child.
     */
    public function testWidthCapsTheImageNotTheFigure(): void
    {
        $html = $this->render(['size' => 'md', 'align' => 'end']);

        // <img> carries the display width...
        $this->assertMatchesRegularExpression('/<img[^>]*style="[^"]*width:800px/', $html);
        // ...and the <figure> carries no inline style at all.
        $this->assertMatchesRegularExpression('/<figure class="cb-kit-image cb-kit-image--end">/', $html);
        $this->assertDoesNotMatchRegularExpression('/<figure[^>]*style=/', $html);
    }

    public function testCustomWidthCapsTheImage(): void
    {
        $html = $this->render(['size' => 'custom', 'customWidth' => 512, 'align' => 'start']);

        $this->assertMatchesRegularExpression('/<img[^>]*style="[^"]*width:512px/', $html);
        $this->assertDoesNotMatchRegularExpression('/<figure[^>]*style=/', $html);
    }

    /**
     * With the default (passthrough) resolver the markup is what it always was:
     * the stored src, and not a trace of the seam — no `srcset`, no `sizes`.
     */
    public function testPassthroughResolverLeavesTheMarkupUntouched(): void
    {
        $html = $this->render(['size' => 'md']);

        $this->assertStringContainsString('src="https://example.test/photo.jpg"', $html);
        $this->assertStringNotContainsString('srcset', $html);
        $this->assertStringNotContainsString('sizes', $html);
    }

    /**
     * A host that wires a resolver gets responsive candidates out of the same
     * template — and the resolver is handed the display box the view computed,
     * which is the whole reason the seam takes a width/height at all.
     */
    public function testWiredResolverEmitsSrcsetAndReceivesTheDisplayBox(): void
    {
        $resolver = new class implements ImageUrlResolverInterface {
            /** @var list<array{string, int|null, int|null}> */
            public array $calls = [];

            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                $this->calls[] = [$src, $width, $height];

                return new ResolvedImage($src . '?w=' . $width, $src . '?w=400 400w, ' . $src . '?w=800 800w');
            }
        };

        $html = $this->render(['size' => 'custom', 'customWidth' => 800, 'customHeightAuto' => false, 'customHeight' => 300], $resolver);

        $this->assertSame([['https://example.test/photo.jpg', 800, 300]], $resolver->calls);
        $this->assertStringContainsString('src="https://example.test/photo.jpg?w=800"', $html);
        $this->assertStringContainsString('srcset="https://example.test/photo.jpg?w=400 400w, https://example.test/photo.jpg?w=800 800w"', $html);
    }

    /**
     * A `srcset` with no `sizes` is read as 100vw, so a browser would pick the
     * widest candidate for a box that may be a third of the viewport. When the
     * resolver stays silent, the view derives `sizes` from the width it just
     * pinned on the <img>.
     */
    public function testSizesIsDerivedFromTheDisplayWidthWhenTheResolverOmitsIt(): void
    {
        $html = $this->render(['size' => 'lg'], $this->srcsetOnlyResolver());

        $this->assertStringContainsString('sizes="(max-width: 1200px) 100vw, 1200px"', $html);
    }

    /** A resolver that does supply `sizes` is never second-guessed. */
    public function testResolverSizesWins(): void
    {
        $resolver = new class implements ImageUrlResolverInterface {
            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                return new ResolvedImage($src, $src . ' 800w', '50vw');
            }
        };

        $html = $this->render(['size' => 'lg'], $resolver);

        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertStringNotContainsString('100vw', $html);
    }

    /**
     * `full` has no pinned width — the image is fluid. Nothing truthful can be
     * derived, so the view emits no `sizes` and leaves the default to the
     * browser rather than inventing a wrong one.
     */
    public function testNoSizesIsDerivedForAFluidImage(): void
    {
        $html = $this->render(['size' => 'full'], $this->srcsetOnlyResolver());

        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringNotContainsString('sizes=', $html);
    }

    private function srcsetOnlyResolver(): ImageUrlResolverInterface
    {
        return new class implements ImageUrlResolverInterface {
            public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
            {
                return new ResolvedImage($src, $src . ' 800w');
            }
        };
    }

    /** @param array<string, mixed> $data */
    private function render(array $data, ?ImageUrlResolverInterface $resolver = null): string
    {
        $data += ['src' => 'https://example.test/photo.jpg'];

        return $this->makeTwig($resolver)->render('@ContentBlocksKit/block/image/view.html.twig', ['data' => $data]);
    }

    private function makeTwig(?ImageUrlResolverInterface $resolver = null): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new TranslationExtension($this->makeTranslator()));
        $env->addExtension(new ImageExtension($resolver ?? new PassthroughImageUrlResolver()));

        return $env;
    }

    private function makeTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }
}
