<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Rendering;

use ContentBlocks\Rendering\RenderContext;
use PHPUnit\Framework\TestCase;

final class RenderContextTest extends TestCase
{
    public function testThePreviewAndPublicReadsFollowTheMode(): void
    {
        $this->assertTrue(RenderContext::forPreview()->isPreview());
        $this->assertFalse(RenderContext::forPreview()->isPublic());
        $this->assertTrue(RenderContext::forPublic()->isPublic());
        $this->assertFalse(RenderContext::forPublic()->isPreview());
    }

    // An open mode is neither: the renderer pins it before a view reads it.
    public function testAnOpenModeIsNeitherPreviewNorPublic(): void
    {
        $context = RenderContext::forLocale('de');

        $this->assertFalse($context->isPreview());
        $this->assertFalse($context->isPublic());
    }
}
