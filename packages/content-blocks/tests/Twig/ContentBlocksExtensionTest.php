<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Twig\ContentBlocksExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentBlocksExtensionTest extends TestCase
{
    /**
     * Null area short-circuits without touching the BlockRenderer — the host
     * template can call `cb_render_content_area(page.contentArea)` without
     * wrapping it in `{% if page.contentArea %}`.
     *
     * Built via reflection because BlockRenderer is final and instantiating
     * a real one would require pulling in Twig + 6 other deps unrelated to
     * this contract.
     */
    public function testRenderContentAreaReturnsEmptyStringWhenAreaIsNull(): void
    {
        $extension = (new ReflectionClass(ContentBlocksExtension::class))
            ->newInstanceWithoutConstructor();

        $this->assertSame('', $extension->renderContentArea(null));
    }

    /** The link is the resolver's URL as is: no preview flag, so published. */
    public function testPublicUrlIsTheResolverUrlWithoutThePreviewFlag(): void
    {
        $resolver = $this->createMock(ContentAreaUrlResolverInterface::class);
        $resolver->method('resolve')->willReturn('/page/7');

        $extension = new ContentBlocksExtension(
            $this->createMock(BlockRendererInterface::class),
            $resolver,
            (new ReflectionClass(ColorPaletteRegistry::class))->newInstanceWithoutConstructor(),
        );

        $this->assertSame('/page/7', $extension->publicUrl(new ContentArea()));
        $this->assertSame('/page/7?cb_preview=1', $extension->previewUrl(new ContentArea()));
    }
}
