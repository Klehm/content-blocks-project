<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

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
}
