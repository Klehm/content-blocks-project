<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * The three route files a host can import. The default mount must stay
 * byte-for-byte what it was, and the split must leave nothing out.
 */
final class RouteFilesTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../config';

    public function testTheDefaultMountKeepsEveryPathUnderContentBlocks(): void
    {
        $routes = $this->load(self::CONFIG . '/routes.php');

        $this->assertSame('/_content-blocks/types', $routes->get('content_blocks_block_types')?->getPath());
        $this->assertSame('/_content-blocks/area/{id}/publish', $routes->get('content_blocks_area_publish')?->getPath());
        $this->assertSame('/_content-blocks/upload', $routes->get('content_blocks_upload')?->getPath());
        $this->assertSame('/_content-blocks/public/layout', $routes->get('content_blocks_asset_layout')?->getPath());
        foreach ($routes->all() as $name => $route) {
            $this->assertStringStartsWith('/_content-blocks/', $route->getPath(), $name);
        }
    }

    public function testEditorAndPublicSplitTheSameRoutesWithoutAPrefix(): void
    {
        $editor = $this->load(self::CONFIG . '/routes/editor.php');
        $public = $this->load(self::CONFIG . '/routes/public.php');
        $default = $this->load(self::CONFIG . '/routes.php');

        // Public pages link these, so they must never ride the editor mount.
        $publicNames = array_keys($public->all());
        sort($publicNames);
        $this->assertSame([
            'content_blocks_asset_builder',
            'content_blocks_asset_layout',
            'content_blocks_asset_preview_overlay',
            'content_blocks_asset_styling',
        ], $publicNames);
        $this->assertSame('/layout', $public->get('content_blocks_asset_layout')?->getPath());

        $this->assertSame([], array_intersect_key($editor->all(), $public->all()));
        $this->assertSame('/area/{id}/publish', $editor->get('content_blocks_area_publish')?->getPath());

        $names = array_merge(array_keys($editor->all()), $publicNames);
        sort($names);
        $defaultNames = array_keys($default->all());
        sort($defaultNames);
        $this->assertSame($defaultNames, $names);
    }

    private function load(string $file): RouteCollection
    {
        $locator = new FileLocator();
        $attributes = new AttributeRouteControllerLoader();
        $resolver = new LoaderResolver([
            new PhpFileLoader($locator),
            new AttributeDirectoryLoader($locator, $attributes),
            new AttributeFileLoader($locator, $attributes),
            $attributes,
        ]);

        $loader = $resolver->resolve($file);
        $this->assertNotFalse($loader);

        return $loader->load($file);
    }
}
