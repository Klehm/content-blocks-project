<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig;

use ContentBlocks\Twig\RoutingExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RoutingExtensionTest extends TestCase
{
    public function testTheDefaultMount(): void
    {
        $this->assertSame('/_content-blocks', $this->extension('/_content-blocks/types')->apiBase());
    }

    public function testAHostPrefixFollowsTheRouter(): void
    {
        $this->assertSame('/admin/cb', $this->extension('/admin/cb/types')->apiBase());
    }

    /** An app served from a subdirectory: its base URL is part of the path. */
    public function testTheAppBaseUrlIsKept(): void
    {
        $extension = $this->extension('/_content-blocks/types', new RequestContext('/shop/index.php'));

        $this->assertSame('/shop/index.php/_content-blocks', $extension->apiBase());
    }

    /** Routes cherry-picked out of editor.php leave no base to derive. */
    public function testARemappedAnchorRouteIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\LogicException::class);

        $this->extension('/admin/block-types')->apiBase();
    }

    private function extension(string $anchorPath, ?RequestContext $context = null): RoutingExtension
    {
        $routes = new RouteCollection();
        $routes->add('content_blocks_block_types', new Route($anchorPath));

        return new RoutingExtension(new UrlGenerator($routes, $context ?? new RequestContext()));
    }
}
